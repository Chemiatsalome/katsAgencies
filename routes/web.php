<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('dashboard', function () {
    $user = Auth::user();

    return match (true) {
        $user->hasRole('admin') => redirect()->route('admin.dashboard'),
        $user->hasRole('landlord') => redirect()->route('landlord.dashboard'),
        $user->hasRole('tenant') => redirect()->route('tenant.dashboard'),
        default => abort(403),
    };
})->middleware(['auth', 'verified'])->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::view('pending-approval', 'pending-approval')
    ->middleware(['auth'])
    ->name('pending-approval');

// Public tenant self-registration entry point — no auth required to view the
// invitation, the component itself handles both new-account and
// already-logged-in-tenant acceptance paths.
Route::get('invite/{token}', \App\Livewire\TenantInvitations\Accept::class)->name('invitations.accept');

// Public webhook Safaricom calls with the result of an STK push — no session,
// no auth, and must stay outside CSRF protection (see bootstrap/app.php).
Route::post('mpesa/callback', [\App\Http\Controllers\MpesaCallbackController::class, 'handle'])->name('mpesa.callback');

// Kats Agencies platform administration: manages landlords and the platform itself,
// not individual properties/tenants/rent (that is the Landlord module's job).
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', \App\Livewire\Admin\Dashboard::class)->name('dashboard');

    Route::get('landlords', \App\Livewire\Admin\Landlords\Index::class)->name('landlords.index');
    Route::get('landlords/applications', \App\Livewire\Admin\Landlords\Applications::class)->name('landlords.applications');
    Route::get('landlords/properties', \App\Livewire\Admin\Landlords\Properties::class)->name('landlords.properties');
    Route::get('landlords/activity', \App\Livewire\Admin\Landlords\Activity::class)->name('landlords.activity');
    Route::get('landlords/{landlord}', \App\Livewire\Admin\Landlords\Show::class)->name('landlords.show');

    Route::get('platform/overview', \App\Livewire\Admin\Platform\Overview::class)->name('platform.overview');
    Route::get('platform/occupancy', \App\Livewire\Admin\Platform\Occupancy::class)->name('platform.occupancy');

    Route::get('notifications', \App\Livewire\Admin\Notifications\Index::class)->name('notifications.index');

    Route::get('reports/platform', \App\Livewire\Admin\Reports\Platform::class)->name('reports.platform');
    Route::get('reports/landlord', \App\Livewire\Admin\Reports\LandlordReport::class)->name('reports.landlord');

    Route::get('users', \App\Livewire\Admin\Users\Index::class)->name('users.index');
    Route::get('permissions', \App\Livewire\Admin\Permissions\Index::class)->name('permissions.index');
    Route::get('settings', \App\Livewire\Admin\Settings\Index::class)->name('settings.index');
    Route::get('audit-logs', \App\Livewire\Admin\AuditLogs\Index::class)->name('audit-logs.index');
});

Route::middleware(['auth', 'role:landlord'])->prefix('landlord')->name('landlord.')->group(function () {
    Route::get('dashboard', \App\Livewire\Landlord\Dashboard::class)->name('dashboard');
});

// Property/tenant/rent management is exclusively the Landlord's day-to-day domain.
Route::middleware(['auth', 'role:landlord'])->prefix('manage')->name('manage.')->group(function () {
    // My Properties
    Route::get('properties', \App\Livewire\Properties\Index::class)->name('properties.index');
    Route::get('properties/create', \App\Livewire\Properties\Form::class)->name('properties.create');
    Route::get('properties/{property}', \App\Livewire\Properties\Show::class)->name('properties.show');
    Route::get('properties/{property}/edit', \App\Livewire\Properties\Form::class)->name('properties.edit');

    Route::get('properties/{property}/blocks', \App\Livewire\Blocks\Index::class)->name('blocks.index');
    Route::get('properties/{property}/blocks/create', \App\Livewire\Blocks\Form::class)->name('blocks.create');
    Route::get('blocks/{block}/edit', \App\Livewire\Blocks\Form::class)->name('blocks.edit');

    Route::get('units', \App\Livewire\Units\Index::class)->name('units.index');
    Route::get('blocks/{block}/houses', \App\Livewire\Houses\Index::class)->name('houses.index');
    Route::get('blocks/{block}/houses/create', \App\Livewire\Houses\Form::class)->name('houses.create');
    Route::get('houses/{house}', \App\Livewire\Houses\Show::class)->name('houses.show');
    Route::get('houses/{house}/edit', \App\Livewire\Houses\Form::class)->name('houses.edit');

    Route::get('occupancy', \App\Livewire\Occupancy\Index::class)->name('occupancy.index');
    Route::get('vacancies', \App\Livewire\Vacancies\Index::class)->name('vacancies.index');

    // Tenancy
    Route::get('tenants', \App\Livewire\Tenants\Index::class)->name('tenants.index');
    Route::get('tenants/import', \App\Livewire\Tenants\Import::class)->name('tenants.import');
    Route::get('tenants/create', \App\Livewire\Tenants\Form::class)->name('tenants.create');
    Route::get('tenants/{tenant}', \App\Livewire\Tenants\Show::class)->name('tenants.show');
    Route::get('tenants/{tenant}/edit', \App\Livewire\Tenants\Form::class)->name('tenants.edit');

    Route::get('tenancies', \App\Livewire\Tenancies\Index::class)->name('tenancies.index');
    Route::get('tenancies/create', \App\Livewire\Tenancies\Form::class)->name('tenancies.create');
    Route::get('tenancies/{tenancy}', \App\Livewire\Tenancies\Show::class)->name('tenancies.show');

    Route::get('tenant-invitations', \App\Livewire\TenantInvitations\Index::class)->name('tenant-invitations.index');
    Route::get('tenant-invitations/create', \App\Livewire\TenantInvitations\Create::class)->name('tenant-invitations.create');

    Route::get('applications', \App\Livewire\Applications\Index::class)->name('applications.index');
    Route::get('applications/create', \App\Livewire\Applications\Form::class)->name('applications.create');
    Route::get('applications/{application}', \App\Livewire\Applications\Show::class)->name('applications.show');

    // Rent & Finance
    Route::get('rent-charges', \App\Livewire\RentCharges\Index::class)->name('rent-charges.index');
    Route::get('rent-charges/historical-review', \App\Livewire\RentCharges\HistoricalReview::class)->name('rent-charges.historical-review');
    Route::get('arrears', \App\Livewire\Arrears\Index::class)->name('arrears.index');
    Route::get('deposits', \App\Livewire\Deposits\Index::class)->name('deposits.index');
    Route::get('expenses', \App\Livewire\Expenses\Index::class)->name('expenses.index');
    Route::get('expenses/create', \App\Livewire\Expenses\Form::class)->name('expenses.create');
    Route::get('income', \App\Livewire\Income\Index::class)->name('income.index');
    Route::get('income/create', \App\Livewire\Income\Form::class)->name('income.create');
    Route::get('finance/overview', \App\Livewire\Finance\Overview::class)->name('finance.overview');
    Route::get('reports', \App\Livewire\Reports\Index::class)->name('reports.index');

    // Maintenance
    Route::get('maintenance', \App\Livewire\Maintenance\Index::class)->name('maintenance.index');
    Route::get('maintenance/schedules', \App\Livewire\Maintenance\Schedules\Index::class)->name('maintenance.schedules.index');
    Route::get('maintenance/schedules/create', \App\Livewire\Maintenance\Schedules\Form::class)->name('maintenance.schedules.create');
    Route::get('maintenance/schedules/{schedule}/edit', \App\Livewire\Maintenance\Schedules\Form::class)->name('maintenance.schedules.edit');

    // Communication
    Route::get('notifications', \App\Livewire\Notifications\Index::class)->name('notifications.index');
    Route::get('messages', \App\Livewire\Messages\Index::class)->name('messages.index');

    // Documents
    Route::get('documents', \App\Livewire\Documents\Index::class)->name('documents.index');

    // Analytics
    Route::get('analytics/performance', \App\Livewire\Analytics\Performance::class)->name('analytics.performance');

    // Settings
    Route::get('settings', \App\Livewire\Settings\Index::class)->name('settings.index');
});

Route::middleware(['auth', 'role:tenant'])->prefix('tenant')->name('tenant.')->group(function () {
    Route::get('dashboard', \App\Livewire\Tenant\Dashboard::class)->name('dashboard');
    Route::get('rent', \App\Livewire\Tenant\Rent::class)->name('rent');
    Route::get('pay', \App\Livewire\Tenant\PayRent::class)->name('pay');
    Route::get('payments', \App\Livewire\Tenant\Payments::class)->name('payments');
    Route::get('statements', \App\Livewire\Tenant\Statements::class)->name('statements');
    Route::get('tenancy', \App\Livewire\Tenant\MyTenancy::class)->name('tenancy');
    Route::get('contract', \App\Livewire\Tenant\Contract::class)->name('contract');

    Route::get('maintenance', \App\Livewire\Tenant\Maintenance\Index::class)->name('maintenance.index');
    Route::get('maintenance/create', \App\Livewire\Tenant\Maintenance\Create::class)->name('maintenance.create');

    Route::get('notifications', \App\Livewire\Tenant\Notifications\Index::class)->name('notifications.index');
    Route::get('announcements', \App\Livewire\Tenant\Announcements\Index::class)->name('announcements.index');

    Route::get('profile', \App\Livewire\Tenant\Profile::class)->name('profile');
});

require __DIR__.'/auth.php';
