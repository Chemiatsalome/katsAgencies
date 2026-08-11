<?php

namespace App\Livewire\Admin\Settings;

use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use App\Models\Setting;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    public string $tab = 'general';

    // General
    public string $org_name = '';

    public string $org_contact_email = '';

    public string $org_contact_phone = '';

    public string $currency = 'KES';

    public string $date_format = 'd M Y';

    // Rent
    public int $default_due_day = 1;

    public int $grace_period_days = 3;

    public string $late_payment_rule = '';

    public float $arrears_threshold = 0;

    // Notifications
    public int $reminder_days_before = 3;

    public string $email_from_name = '';

    // Finance (new category inputs)
    public string $new_expense_category = '';

    public string $new_income_category = '';

    public function mount(): void
    {
        $this->tab = request()->query('tab', 'general');

        $this->org_name = Setting::get('org_name', 'Kats Agencies');
        $this->org_contact_email = Setting::get('org_contact_email', '');
        $this->org_contact_phone = Setting::get('org_contact_phone', '');
        $this->currency = Setting::get('currency', 'KES');
        $this->date_format = Setting::get('date_format', 'd M Y');

        $this->default_due_day = (int) Setting::get('default_due_day', 1);
        $this->grace_period_days = (int) Setting::get('grace_period_days', 3);
        $this->late_payment_rule = Setting::get('late_payment_rule', '');
        $this->arrears_threshold = (float) Setting::get('arrears_threshold', 0);

        $this->reminder_days_before = (int) Setting::get('reminder_days_before', 3);
        $this->email_from_name = Setting::get('email_from_name', config('app.name'));
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

    public function saveRent(): void
    {
        $this->validate([
            'default_due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:60'],
            'late_payment_rule' => ['nullable', 'string'],
            'arrears_threshold' => ['required', 'numeric', 'min:0'],
        ]);

        foreach (['default_due_day', 'grace_period_days', 'late_payment_rule', 'arrears_threshold'] as $key) {
            Setting::set($key, $this->$key);
        }

        session()->flash('status', 'Rent settings saved.');
    }

    public function saveNotifications(): void
    {
        $this->validate([
            'reminder_days_before' => ['required', 'integer', 'min:0', 'max:30'],
            'email_from_name' => ['required', 'string', 'max:255'],
        ]);

        foreach (['reminder_days_before', 'email_from_name'] as $key) {
            Setting::set($key, $this->$key);
        }

        session()->flash('status', 'Notification settings saved.');
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
