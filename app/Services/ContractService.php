<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Document;
use App\Models\LandlordSetting;
use App\Models\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// The first "generate a PDF and persist it as a Document row, with its own
// lifecycle state" pattern in this codebase — every prior dompdf usage
// (Reports, Tenant\Statements) only streams an ephemeral download and never
// saves anything. Phase 4 (receipts/notices) is expected to reuse this shape.
class ContractService
{
    public function generateForTenancy(Tenancy $tenancy, ?ContractTemplate $template = null): Contract
    {
        $property = $tenancy->house->block->property;
        $landlordId = $property->landlord_id;

        // A property's own default wins over the landlord's portfolio-wide
        // fallback — different buildings can carry different agreements
        // (e.g. a furnished unit's terms vs. an unfurnished one's) instead
        // of every tenancy being forced onto one single template.
        $template ??= ContractTemplate::resolveDefaultFor($landlordId, $property->id);

        if (! $template) {
            throw new \RuntimeException('No active default contract template is configured for this landlord or property.');
        }

        $body = $this->substitutePlaceholders($template->body, $tenancy);
        $pdf = Pdf::loadView('contracts.pdf', ['body' => $body]);

        $path = 'documents/contracts/'.Str::random(40).'.pdf';
        Storage::disk('local')->put($path, $pdf->output());

        $document = Document::create([
            'documentable_type' => $tenancy->getMorphClass(),
            'documentable_id' => $tenancy->id,
            'category' => 'lease_agreement',
            'original_filename' => "Lease Agreement - {$tenancy->tenant->name}.pdf",
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => Storage::disk('local')->size($path),
            'uploaded_by' => Auth::id(),
        ]);

        $previousVersion = $this->supersedeCurrentVersion($tenancy);

        return Contract::create([
            'tenancy_id' => $tenancy->id,
            'contract_template_id' => $template->id,
            'document_id' => $document->id,
            'source' => 'system_template',
            'status' => 'generated',
            'body_snapshot' => $body,
            'version' => $previousVersion + 1,
        ]);
    }

    /**
     * A landlord's own agreement, preserved exactly as uploaded — no
     * rewriting, no PDF regeneration. Produces the same Contract row shape
     * as generateForTenancy() so the rest of the lifecycle (send/sign/
     * download) never needs to know which source it came from.
     */
    public function uploadForTenancy(Tenancy $tenancy, UploadedFile $file, int $uploadedBy): Contract
    {
        $path = $file->store('documents/contracts', 'local');

        $document = Document::create([
            'documentable_type' => $tenancy->getMorphClass(),
            'documentable_id' => $tenancy->id,
            'category' => 'lease_agreement',
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $uploadedBy,
        ]);

        $previousVersion = $this->supersedeCurrentVersion($tenancy);

        return Contract::create([
            'tenancy_id' => $tenancy->id,
            'contract_template_id' => null,
            'document_id' => $document->id,
            'source' => 'landlord_upload',
            'status' => 'generated',
            'body_snapshot' => null,
            'version' => $previousVersion + 1,
        ]);
    }

    /**
     * Marks the tenancy's current latest contract (whatever its status —
     * draft, sent, even signed) as superseded rather than leaving it to be
     * silently shadowed by the new row that's about to outrank it on
     * version. Its own status/signed_by_name/signed_at are left untouched,
     * so a signed original stays provably signed even after a revision
     * replaces it. Returns its version number (0 if this is the first).
     */
    private function supersedeCurrentVersion(Tenancy $tenancy): int
    {
        $current = $tenancy->contracts()->whereNull('superseded_at')->orderByDesc('version')->first();

        $current?->update(['superseded_at' => now()]);

        return $current?->version ?? 0;
    }

    public function markSent(Contract $contract): void
    {
        $contract->update([
            'status' => 'awaiting_signature',
            'sent_at' => now(),
        ]);
    }

    public function sign(Contract $contract, string $signedByName, string $ip): void
    {
        $contract->update([
            'status' => 'signed',
            'signed_by_name' => $signedByName,
            'signed_at' => now(),
            'signed_ip' => $ip,
        ]);
    }

    public function decline(Contract $contract, ?string $reason): void
    {
        $contract->update([
            'status' => 'declined',
            'declined_at' => now(),
            'declined_reason' => $reason,
        ]);
    }

    private function substitutePlaceholders(string $body, Tenancy $tenancy): string
    {
        $house = $tenancy->house;
        $block = $house->block;
        $property = $block->property;
        $landlord = $property->landlord;
        $tenant = $tenancy->tenant;
        $landlordId = $property->landlord_id;
        $direct = app(PaymentGatewayService::class)->isDirectlyConfigured($landlordId);
        $mpesaShortcode = $direct
            ? (string) LandlordSetting::get($landlordId, 'payout_shortcode', '')
            : (string) config('services.mpesa.shortcode', '');

        return strtr($body, [
            '{{tenant_name}}' => $tenant->name,
            '{{tenant_id_number}}' => $tenant->id_number ?? '',
            '{{tenant_phone}}' => $tenant->phone ?? '',
            '{{tenant_email}}' => $tenant->email ?? '',
            '{{property_name}}' => $property->name,
            '{{property_address}}' => $property->address ?? '',
            '{{block_name}}' => $block->name,
            '{{unit_number}}' => $house->house_number,
            '{{monthly_rent}}' => number_format((float) $tenancy->monthly_rent, 2),
            '{{deposit_amount}}' => number_format((float) $tenancy->deposit_required, 2),
            '{{tenancy_start_date}}' => $tenancy->move_in_date->format('d M Y'),
            '{{tenancy_end_date}}' => $tenancy->lease_end_date?->format('d M Y') ?? 'Open-ended',
            '{{rent_due_day}}' => Tenancy::ordinalDay($tenancy->dueDay()),
            '{{late_fee_percentage}}' => (string) LandlordSetting::get($landlordId, 'late_fee_percentage', '5'),
            '{{notice_period_days}}' => (string) ($tenancy->notice_period_days ?? '60'),
            '{{agreement_date}}' => now()->format('d F Y'),
            '{{payment_instructions}}' => match (true) {
                (bool) $mpesaShortcode && $direct => "Pay via the Kats Agencies tenant portal (M-Pesa, Paybill {$mpesaShortcode}), or as advised by the landlord",
                (bool) $mpesaShortcode => "Pay via the Kats Agencies tenant portal (M-Pesa, Kats Agencies Paybill {$mpesaShortcode}), or as advised by the landlord",
                default => 'as advised by the landlord',
            },
            '{{landlord_name}}' => $landlord->name,
            '{{landlord_phone}}' => $landlord->phone ?? '',
            '{{landlord_email}}' => $landlord->email,
            '{{landlord_address}}' => LandlordSetting::get($landlordId, 'contract_landlord_address', ''),
            '{{agency_name}}' => LandlordSetting::get($landlordId, 'contract_agency_name', ''),
            '{{supervisor_name}}' => LandlordSetting::get($landlordId, 'contract_supervisor_name', ''),
            '{{supervisor_phone}}' => LandlordSetting::get($landlordId, 'contract_supervisor_phone', ''),
            '{{drawn_by_name}}' => LandlordSetting::get($landlordId, 'contract_drawn_by_name', ''),
        ]);
    }
}
