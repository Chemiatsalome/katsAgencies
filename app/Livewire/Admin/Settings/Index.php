<?php

namespace App\Livewire\Admin\Settings;

use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use App\Models\Setting;
use App\Services\PaymentGatewayService;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Attributes\Layout;
use RuntimeException;

#[Layout('layouts.admin')]
class Index extends Component
{
    public const TABS = ['general', 'finance', 'property', 'payments'];

    public string $tab = 'general';

    // General
    public string $org_name = '';

    public string $org_contact_email = '';

    public string $org_contact_phone = '';

    public string $currency = 'KES';

    public string $date_format = 'd M Y';

    // Finance (new category inputs)
    public string $new_expense_category = '';

    public string $new_income_category = '';

    // Payments — Kats' own M-Pesa paybill, previously .env-only (see
    // PaymentGatewayService::platformSetting()).
    public string $mpesa_env = 'sandbox';

    public string $mpesa_consumer_key = '';

    public string $mpesa_consumer_secret = '';

    public string $mpesa_shortcode = '';

    public string $mpesa_callback_url = '';

    public string $mpesa_passkey = '';

    public string $mpesa_callback_secret = '';

    public bool $platformConfigured = false;

    public function mount(): void
    {
        // Rent and Notifications tabs were removed — both wrote to this
        // global Setting store, but nothing in the app ever read those
        // values back. Every actual due-day/grace-period/late-fee/reminder
        // behavior is controlled per-landlord (LandlordSetting, on each
        // landlord's own Settings page), so the admin-level forms did
        // nothing. A stale ?tab=rent or ?tab=notifications link (bookmark,
        // old nav) falls back to General rather than rendering nothing.
        $requestedTab = request()->query('tab', 'general');
        $this->tab = in_array($requestedTab, self::TABS, true) ? $requestedTab : 'general';

        $this->org_name = Setting::get('org_name', 'Kats Agencies');
        $this->org_contact_email = Setting::get('org_contact_email', '');
        $this->org_contact_phone = Setting::get('org_contact_phone', '');
        $this->currency = Setting::get('currency', 'KES');
        $this->date_format = Setting::get('date_format', 'd M Y');

        $this->mpesa_env = (string) Setting::get('mpesa_env', config('services.mpesa.env', 'sandbox'));
        $this->mpesa_consumer_key = (string) Setting::get('mpesa_consumer_key', config('services.mpesa.consumer_key', ''));
        $this->mpesa_shortcode = (string) Setting::get('mpesa_shortcode', config('services.mpesa.shortcode', ''));
        $this->mpesa_callback_url = (string) Setting::get('mpesa_callback_url', config('services.mpesa.callback_url', ''));
        // The callback secret isn't a Safaricom-issued credential (it's
        // generated here, see generateCallbackSecret()) — shown in full so
        // an admin can actually copy it into wherever the webhook URL
        // needs registering, unlike consumer_secret/passkey below.
        $this->mpesa_callback_secret = (string) Setting::getEncrypted('mpesa_callback_secret', config('services.mpesa.callback_secret', ''));

        $this->platformConfigured = app(PaymentGatewayService::class)->isPlatformConfigured();
    }

    public function generateCallbackSecret(): void
    {
        $this->mpesa_callback_secret = Str::random(40);
    }

    public function savePayments(): void
    {
        $data = $this->validate([
            'mpesa_env' => ['required', 'in:sandbox,production'],
            'mpesa_consumer_key' => ['required', 'string', 'max:255'],
            'mpesa_consumer_secret' => ['nullable', 'string', 'max:255'],
            'mpesa_shortcode' => ['required', 'string', 'max:20'],
            'mpesa_callback_url' => ['required', 'url', 'max:255'],
            'mpesa_passkey' => ['nullable', 'string', 'max:255'],
            'mpesa_callback_secret' => ['required', 'string', 'min:20', 'max:255'],
        ]);

        Setting::set('mpesa_env', $data['mpesa_env']);
        Setting::set('mpesa_consumer_key', $data['mpesa_consumer_key']);
        Setting::set('mpesa_shortcode', $data['mpesa_shortcode']);
        Setting::set('mpesa_callback_url', $data['mpesa_callback_url']);
        Setting::setEncrypted('mpesa_callback_secret', $data['mpesa_callback_secret']);

        // Write-only fields — a blank submission means "keep the existing
        // one", same convention the landlord payout passkey field uses.
        if (filled($data['mpesa_consumer_secret'])) {
            Setting::setEncrypted('mpesa_consumer_secret', $data['mpesa_consumer_secret']);
        }
        if (filled($data['mpesa_passkey'])) {
            Setting::setEncrypted('mpesa_passkey', $data['mpesa_passkey']);
        }

        $this->reset('mpesa_consumer_secret', 'mpesa_passkey');
        $this->platformConfigured = app(PaymentGatewayService::class)->isPlatformConfigured();

        session()->flash('status', 'Payment settings saved.');
    }

    public function testConnection(): void
    {
        try {
            app(PaymentGatewayService::class)->testPlatformConnection();
            session()->flash('status', 'Connected — Safaricom accepted these credentials.');
        } catch (RuntimeException $e) {
            $this->addError('mpesa_consumer_key', $e->getMessage());
        }
    }

    public function saveGeneral(): void
    {
        $this->validate([
            'org_name' => ['required', 'string', 'max:255'],
            'org_contact_email' => ['nullable', 'email'],
            'org_contact_phone' => ['nullable', 'string', 'max:32'],
            'currency' => ['required', 'string', 'max:8'],
            'date_format' => ['required', 'string', 'max:32'],
        ]);

        foreach (['org_name', 'org_contact_email', 'org_contact_phone', 'currency', 'date_format'] as $key) {
            Setting::set($key, $this->$key);
        }

        session()->flash('status', 'General settings saved.');
    }

    public function addExpenseCategory(): void
    {
        $this->validate(['new_expense_category' => ['required', 'string', 'max:255', 'unique:expense_categories,name']]);
        ExpenseCategory::create(['name' => $this->new_expense_category]);
        $this->new_expense_category = '';
    }

    public function toggleExpenseCategory(int $id): void
    {
        $category = ExpenseCategory::findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
    }

    public function addIncomeCategory(): void
    {
        $this->validate(['new_income_category' => ['required', 'string', 'max:255', 'unique:income_categories,name']]);
        IncomeCategory::create(['name' => $this->new_income_category]);
        $this->new_income_category = '';
    }

    public function toggleIncomeCategory(int $id): void
    {
        $category = IncomeCategory::findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
    }

    public function render()
    {
        return view('livewire.admin.settings.index', [
            'expenseCategories' => ExpenseCategory::orderBy('name')->get(),
            'incomeCategories' => IncomeCategory::orderBy('name')->get(),
        ]);
    }
}
