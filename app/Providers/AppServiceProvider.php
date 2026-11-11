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

        // Registered -> SendEmailVerificationNotification, and discovering
        // app/Listeners/ActivateApprovedLandlordOnVerification for Verified
        // (see its own docblock for what that one does), are BOTH already
        // wired up by Laravel itself — Illuminate\Foundation\Application
        // registers an internal base Illuminate\Foundation\Support\
        // Providers\EventServiceProvider unconditionally for every app,
        // independent of bootstrap/providers.php, and its
        // configureEmailVerification() + event-discovery-on-app/Listeners
        // cover both of these with zero app code. Re-registering either
        // one here (as this used to) double-registers it: two listener
        // instances firing off the one Registered event per registration
        // sent two verification emails per signup, each with its own
        // now()-based expiry and signature.
        //
        // Every self-registration/reset flow (landlord register, password
        // reset, tenant/manager invite-accept) previously fell through to
        // no app-wide floor at all — just whatever ad hoc rule each form
        // happened to validate locally.
        Password::defaults(fn () => Password::min(10)->letters()->numbers());
    }
}
