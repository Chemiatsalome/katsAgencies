<?php

namespace App\Providers;

use App\Models\DepositPayment;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Observers\DepositPaymentObserver;
use App\Observers\PaymentObserver;
use App\Observers\TenancyObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        // Laravel 11+'s skeleton no longer ships a default
        // EventServiceProvider, so this has to be registered explicitly —
        // without it, User::implements(MustVerifyEmail) alone would never
        // actually send a verification email after registration.
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        // Every self-registration/reset flow (landlord register, password
        // reset, tenant/manager invite-accept) previously fell through to
        // no app-wide floor at all — just whatever ad hoc rule each form
        // happened to validate locally.
        Password::defaults(fn () => Password::min(10)->letters()->numbers());
    }
}
