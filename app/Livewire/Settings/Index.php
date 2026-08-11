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
    public string $tab = 'payment_gateway';

    public string $mpesa_shortcode = '';

    public string $mpesa_shortcode_type = 'paybill';

    public string $mpesa_consumer_key = '';

    public string $mpesa_consumer_secret = '';

    public string $mpesa_passkey = '';

    public string $reminder_days_before = '7,3,1';

    public string $reminder_days_after = '1,3,7,14,30';

    /** @var string[] */
    public array $reminder_channels = ['in_app'];

    public string $financial_start_date = '';

    public function mount(): void
    {
        $userId = Auth::id();

        $this->mpesa_shortcode = (string) LandlordSetting::get($userId, 'mpesa_shortcode', '');
        $this->mpesa_shortcode_type = (string) LandlordSetting::get($userId, 'mpesa_shortcode_type', 'paybill');
        $this->reminder_days_before = (string) LandlordSetting::get($userId, 'reminder_days_before', '7,3,1');
        $this->reminder_days_after = (string) LandlordSetting::get($userId, 'reminder_days_after', '1,3,7,14,30');

        $channels = (string) LandlordSetting::get($userId, 'reminder_channels', 'in_app');
        $this->reminder_channels = array_filter(explode(',', $channels)) ?: ['in_app'];

        $this->financial_start_date = (string) LandlordSetting::get(
            $userId, 'financial_start_date', now()->startOfMonth()->format('Y-m-d')
        );
    }

    public function saveGateway(): void
    {
        $data = $this->validate([
            'mpesa_shortcode' => ['nullable', 'string', 'max:20'],
            'mpesa_shortcode_type' => ['required', 'in:paybill,till'],
            'mpesa_consumer_key' => ['nullable', 'string'],
            'mpesa_consumer_secret' => ['nullable', 'string'],
            'mpesa_passkey' => ['nullable', 'string'],
        ]);

        $userId = Auth::id();

        LandlordSetting::set($userId, 'mpesa_shortcode', $data['mpesa_shortcode']);
        LandlordSetting::set($userId, 'mpesa_shortcode_type', $data['mpesa_shortcode_type']);

        foreach (['mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_passkey'] as $field) {
            if (filled($data[$field])) {
                LandlordSetting::setEncrypted($userId, $field, $data[$field]);
            }
        }

        $this->reset(['mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_passkey']);
        session()->flash('status', 'Payment gateway settings saved.');
    }

    public function saveReminders(): void
    {
        $data = $this->validate([
            'reminder_days_before' => ['required', 'string'],
            'reminder_days_after' => ['required', 'string'],
            'reminder_channels' => ['required', 'array', 'min:1'],
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

    public function render()
    {
        return view('livewire.settings.index', [
            'gatewayConfigured' => app(PaymentGatewayService::class)->isConfigured(Auth::id()),
        ]);
    }
}
