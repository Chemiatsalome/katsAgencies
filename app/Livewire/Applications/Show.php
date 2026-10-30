<?php

namespace App\Livewire\Applications;

use App\Models\Application;
use App\Models\House;
use App\Models\Tenant;
use App\Services\TenancyProvisioningService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Show extends Component
{
    public Application $application;

    public bool $showApproveForm = false;

    public ?int $house_id = null;

    public string $move_in_date;

    public string $monthly_rent = '';

    public string $deposit_required = '';

    public function mount(Application $application): void
    {
        $this->authorize('view', $application);
        $this->application = $application;
        $this->house_id = $application->desired_house_id;
        $this->move_in_date = now()->format('Y-m-d');

        if ($this->house_id) {
            $this->syncRentFromHouse();
        }
    }

    public function updatedHouseId(): void
    {
        $this->syncRentFromHouse();
    }

    private function syncRentFromHouse(): void
    {
        $house = House::find($this->house_id);
        if ($house) {
            $this->monthly_rent = (string) $house->monthly_rent;
            $this->deposit_required = (string) $house->deposit_amount;
        }
    }

    public function updateStatus(string $status): void
    {
        $this->authorize('update', $this->application);

        abort_unless(in_array($status, ['submitted', 'under_review', 'more_info_required', 'rejected', 'withdrawn'], true), 422);

        $this->application->update(['status' => $status, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);

        session()->flash('status', match ($status) {
            'under_review' => 'Marked as under review.',
            'more_info_required' => 'More information requested from the applicant.',
            'rejected' => 'Application rejected.',
            'withdrawn' => 'Application marked as withdrawn.',
            default => 'Status updated.',
        });
    }

    public function approveAndConvert(): void
    {
        $this->authorize('update', $this->application);

        $this->validate([
            'house_id' => ['required', 'exists:houses,id'],
            'move_in_date' => ['required', 'date'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'deposit_required' => ['required', 'numeric', 'min:0'],
        ]);

        $house = House::findOrFail($this->house_id);
        $this->authorize('update', $house);

        if ($house->status !== 'vacant') {
            $this->addError('house_id', 'This unit is no longer vacant — it may have just been let to someone else.');

            return;
        }

        $tenant = Tenant::firstOrCreate(
            ['phone' => $this->application->applicant_phone],
            ['name' => $this->application->applicant_name, 'email' => $this->application->applicant_email]
        );

        $tenancy = app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => $this->move_in_date,
            'monthly_rent' => $this->monthly_rent,
            'deposit_required' => $this->deposit_required,
            'rent_due_day' => $tenant->payment_due_day,
        ]);

        $this->application->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'converted_tenancy_id' => $tenancy->id,
        ]);

        session()->flash('status', 'Application approved and tenancy created.');
        $this->redirectRoute('manage.tenancies.show', $tenancy, navigate: true);
    }

    public function render()
    {
        return view('livewire.applications.show');
    }
}
