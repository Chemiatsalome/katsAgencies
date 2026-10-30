<?php

namespace App\Livewire\Settings;

use App\Models\LandlordSetting;
use App\Services\PaymentGatewayService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    #[Url]
    public string $tab = 'payout';

    public string $payout_shortcode_type = 'paybill';

    public string $payout_shortcode = '';

    public string $mpesa_passkey = '';

    public bool $directCollectionConfigured = false;

    public string $reminder_days_before = '7,3,1';

    public string $reminder_days_after = '1,3,7,14,30';

    /** @var string[] */
    public array $reminder_channels = ['in_app'];

    public string $financial_start_date = '';

    public bool $late_fee_enabled = false;

    public string $late_fee_percentage = '5';

    public string $late_fee_grace_days = '5';

    public string $default_rent_due_day = '5';

    public string $contract_agency_name = '';

    public string $contract_supervisor_name = '';

    public string $contract_supervisor_phone = '';

    public string $contract_drawn_by_name = '';

    public string $renewal_review_cycle_years = '2';

    // Account-wide Settings is landlord-only, full stop — there's no
    // delegable permission for it at all (unlike the groups in
    // PermissionSeeder), so a manager is blocked here even if the route
    // middleware is ever loosened. Payout destination alone is reason
    // enough: it's where tenant rent actually settles.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('landlord'), 403);

        $userId = Auth::id();

        $this->payout_shortcode_type = (string) LandlordSetting::get($userId, 'payout_shortcode_type', 'paybill');
        $this->payout_shortcode = (string) LandlordSetting::get($userId, 'payout_shortcode', '');
        $this->directCollectionConfigured = app(PaymentGatewayService::class)->isDirectlyConfigured($userId);

        $this->reminder_days_before = (string) LandlordSetting::get($userId, 'reminder_days_before', '7,3,1');
        $this->reminder_days_after = (string) LandlordSetting::get($userId, 'reminder_days_after', '1,3,7,14,30');

        $channels = (string) LandlordSetting::get($userId, 'reminder_channels', 'in_app');
        $this->reminder_channels = array_filter(explode(',', $channels)) ?: ['in_app'];

        $this->financial_start_date = (string) LandlordSetting::get(
            $userId, 'financial_start_date', now()->startOfMonth()->format('Y-m-d')
        );

        $this->late_fee_enabled = LandlordSetting::get($userId, 'late_fee_enabled', '') === '1';
        $this->late_fee_percentage = (string) LandlordSetting::get($userId, 'late_fee_percentage', '5');
        $this->late_fee_grace_days = (string) LandlordSetting::get($userId, 'late_fee_grace_days', '5');
        $this->default_rent_due_day = (string) LandlordSetting::get($userId, 'default_rent_due_day', '5');

        $this->contract_agency_name = (string) LandlordSetting::get($userId, 'contract_agency_name', '');
        $this->contract_supervisor_name = (string) LandlordSetting::get($userId, 'contract_supervisor_name', '');
        $this->contract_supervisor_phone = (string) LandlordSetting::get($userId, 'contract_supervisor_phone', '');
        $this->contract_drawn_by_name = (string) LandlordSetting::get($userId, 'contract_drawn_by_name', '');

        $this->renewal_review_cycle_years = (string) LandlordSetting::get($userId, 'renewal_review_cycle_years', '2');
    }

    public function savePayout(): void
    {
        $data = $this->validate([
            'payout_shortcode_type' => ['required', 'in:paybill,till'],
            'payout_shortcode' => ['required', 'string', 'max:20'],
            'mpesa_passkey' => ['nullable', 'string'],
        ]);

        $userId = Auth::id();

        LandlordSetting::set($userId, 'payout_shortcode_type', $data['payout_shortcode_type']);
        LandlordSetting::set($userId, 'payout_shortcode', $data['payout_shortcode']);

        if (filled($data['mpesa_passkey'])) {
            LandlordSetting::setEncrypted($userId, 'mpesa_passkey', $data['mpesa_passkey']);
        }

        $this->reset('mpesa_passkey');
        $this->directCollectionConfigured = app(PaymentGatewayService::class)->isDirectlyConfigured($userId);

        session()->flash('status', 'Payout destination saved.');
    }

    public function disableDirectCollection(): void
    {
        $userId = Auth::id();

        LandlordSetting::forget($userId, 'mpesa_passkey');
        $this->directCollectionConfigured = false;

        session()->flash('status', 'Direct collection turned off — rent will go through Kats Agencies\' paybill and be disbursed to you instead.');
    }

    public function saveReminders(): void
    {
        $data = $this->validate([
            'reminder_days_before' => ['required', 'string'],
            'reminder_days_after' => ['required', 'string'],
            'reminder_channels' => ['required', 'array', 'min:1'],
            'reminder_channels.*' => ['in:in_app,email'],
        ]);

        $userId = Auth::id();

        LandlordSetting::set($userId, 'reminder_days_before', $data['reminder_days_before']);
        LandlordSetting::set($userId, 'reminder_days_after', $data['reminder_days_after']);
        LandlordSetting::set($userId, 'reminder_channels', implode(',', $data['reminder_channels']));

        session()->flash('status', 'Rent reminder settings saved.');
    }

    public function saveFinancial(): void
    {
        $data = $this->validate([
            'financial_start_date' => ['required', 'date'],
        ]);

        LandlordSetting::set(Auth::id(), 'financial_start_date', $data['financial_start_date']);

        session()->flash('status', 'Financial start date saved.');
    }

    public function saveLateFees(): void
    {
        $data = $this->validate([
            'late_fee_enabled' => ['boolean'],
            'late_fee_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'late_fee_grace_days' => ['required', 'integer', 'min:0', 'max:60'],
            'default_rent_due_day' => ['required', 'integer', 'min:1', 'max:31'],
        ]);

        $userId = Auth::id();

        LandlordSetting::set($userId, 'late_fee_enabled', $data['late_fee_enabled'] ? '1' : '0');
        LandlordSetting::set($userId, 'late_fee_percentage', $data['late_fee_percentage']);
        LandlordSetting::set($userId, 'late_fee_grace_days', $data['late_fee_grace_days']);
        LandlordSetting::set($userId, 'default_rent_due_day', $data['default_rent_due_day']);

        session()->flash('status', 'Late fee settings saved.');
    }

    public function saveContractLetterhead(): void
    {
        $data = $this->validate([
            'contract_agency_name' => ['nullable', 'string', 'max:255'],
            'contract_supervisor_name' => ['nullable', 'string', 'max:255'],
            'contract_supervisor_phone' => ['nullable', 'string', 'max:20'],
            'contract_drawn_by_name' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = Auth::id();
        foreach ($data as $key => $value) {
            LandlordSetting::set($userId, $key, (string) $value);
        }

        session()->flash('status', 'Contract letterhead settings saved.');
    }

    public function saveRenewalPolicy(): void
    {
        $data = $this->validate([
            'renewal_review_cycle_years' => ['required', 'in:1,2'],
        ]);

        LandlordSetting::set(Auth::id(), 'renewal_review_cycle_years', $data['renewal_review_cycle_years']);

        session()->flash('status', 'Tenancy renewal policy saved.');
    }

    public function render()
    {
        return view('livewire.settings.index');
    }
}
