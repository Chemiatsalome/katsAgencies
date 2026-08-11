<?php

namespace App\Providers;

use App\Models\DepositPayment;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Observers\DepositPaymentObserver;
use App\Observers\PaymentObserver;
use App\Observers\TenancyObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Payment::observe(PaymentObserver::class);
        DepositPayment::observe(DepositPaymentObserver::class);
        Tenancy::observe(TenancyObserver::class);

        Gate::before(fn ($user) => $user->hasRole('admin') ? true : null);
    }
}
