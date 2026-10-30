<?php

namespace App\Livewire\Admin\Disbursements;

use App\Models\Disbursement;
use App\Models\Document;
use App\Models\LandlordSetting;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    public const DESTINATION_TYPES = ['paybill' => 'Paybill', 'till' => 'Till', 'bank' => 'Bank Transfer', 'cash' => 'Cash'];

    public ?int $disbursingLandlordId = null;

    public string $amount = '';

    public string $disbursedAt = '';

    public string $destinationType = '';

    public string $destinationShortcode = '';

    public string $reference = '';

    public string $notes = '';

    public string $payoutPeriodStart = '';

    public string $payoutPeriodEnd = '';

    public $evidence;

    public function startDisburse(int $landlordId): void
    {
        $this->resetErrorBag();
        $this->disbursingLandlordId = $landlordId;
        $this->amount = number_format(app(DisbursementService::class)->owed($landlordId), 2, '.', '');
        $this->disbursedAt = now()->toDateString();
        $this->destinationType = (string) LandlordSetting::get($landlordId, 'payout_shortcode_type', 'paybill');
        $this->destinationShortcode = (string) LandlordSetting::get($landlordId, 'payout_shortcode', '');
        $this->reference = '';
        $this->notes = '';
        $this->payoutPeriodStart = '';
        $this->payoutPeriodEnd = '';
        $this->evidence = null;
    }

    public function cancelDisburse(): void
    {
        $this->reset([
            'disbursingLandlordId', 'amount', 'disbursedAt', 'destinationType', 'destinationShortcode',
            'reference', 'notes', 'payoutPeriodStart', 'payoutPeriodEnd', 'evidence',
        ]);
    }

    public function confirmDisburse(): void
    {
        $data = $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'disbursedAt' => ['required', 'date', 'before_or_equal:today'],
            'destinationType' => ['required', 'in:'.implode(',', array_keys(self::DESTINATION_TYPES))],
            'destinationShortcode' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payoutPeriodStart' => ['nullable', 'date'],
            'payoutPeriodEnd' => ['nullable', 'date', 'after_or_equal:payoutPeriodStart'],
            'evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        try {
            $disbursement = app(DisbursementService::class)->recordDisbursement(
                $this->disbursingLandlordId,
                (float) $data['amount'],
                $data['reference'] ?: null,
                $data['notes'] ?: null,
                Auth::id(),
                $data['payoutPeriodStart'] ?: null,
                $data['payoutPeriodEnd'] ?: null,
                $data['disbursedAt'],
                $data['destinationType'],
                $data['destinationShortcode'] ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        if ($this->evidence) {
            $path = $this->evidence->store('documents', 'local');

            Document::create([
                'documentable_type' => $disbursement->getMorphClass(),
                'documentable_id' => $disbursement->id,
                'category' => 'payout_evidence',
                'original_filename' => $this->evidence->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $this->evidence->getMimeType(),
                'size' => $this->evidence->getSize(),
                'uploaded_by' => Auth::id(),
            ]);
        }

        $landlord = User::find($this->disbursingLandlordId);

        app(NotificationService::class)->notify(
            $landlord,
            'disbursement_awaiting_confirmation',
            'Payout Recorded — Please Confirm',
            'Kats Agencies recorded a KSh '.number_format($disbursement->amount, 2).' payout to you'
                .($disbursement->reference ? " (ref {$disbursement->reference})" : '').'. Please confirm receipt or report a problem.',
            ['in_app', 'email'],
            $disbursement,
        );

        session()->flash('status', 'Recorded KSh '.number_format($disbursement->amount, 2)." disbursed to {$landlord?->name} — awaiting their confirmation.");
        $this->reset([
            'disbursingLandlordId', 'amount', 'disbursedAt', 'destinationType', 'destinationShortcode',
            'reference', 'notes', 'payoutPeriodStart', 'payoutPeriodEnd', 'evidence',
        ]);
    }

    public function render()
    {
        $service = app(DisbursementService::class);
        $gateway = app(PaymentGatewayService::class);

        // Paginated before the per-landlord figures are computed — mirrors
        // Admin\Platform\Overview, so the collected/disbursed/owed math only
        // runs for the landlords actually shown on this page.
        $landlords = User::approvedLandlords()
            ->orderBy('name')
            ->paginate(20)
            ->through(function (User $landlord) use ($service, $gateway) {
                $collected = $service->collectedViaMpesa($landlord->id);
                $disbursed = $service->totalDisbursed($landlord->id);

                return (object) [
                    'landlord' => $landlord,
                    'collected' => $collected,
                    'disbursed' => $disbursed,
                    'owed' => round($collected - $disbursed, 2),
                    'payoutConfigured' => $service->isPayoutConfigured($landlord->id),
                    'directCollection' => $gateway->isDirectlyConfigured($landlord->id),
                    'collectedDirectly' => $service->collectedDirectly($landlord->id),
                    'lastDisbursement' => Disbursement::where('landlord_id', $landlord->id)->latest('disbursed_at')->first(),
                    'awaitingConfirmationCount' => Disbursement::where('landlord_id', $landlord->id)->awaitingConfirmation()->count(),
                    'disputedCount' => Disbursement::where('landlord_id', $landlord->id)->disputed()->count(),
                ];
            });

        return view('livewire.admin.disbursements.index', [
            'landlords' => $landlords,
            'daysUntilNextRelease' => $service->daysUntilNextRelease(),
        ]);
    }
}
