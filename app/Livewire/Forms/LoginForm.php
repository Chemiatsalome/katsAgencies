<?php

namespace App\Livewire\Forms;

use App\Models\Setting;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only(['email', 'password']), $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'form.email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // A brand-new landlord signup pending its first admin review is a
        // valid destination (the pending-approval page), not a login
        // failure — everything else inactive/suspended means access is off,
        // and the user should be told that here rather than being logged in
        // and silently bounced back out by EnsureAccountIsActive.
        $user = Auth::user();

        if ($user->status !== 'active' && ! ($user->status === 'inactive' && $user->hasRole('landlord'))) {
            Auth::logout();

            throw ValidationException::withMessages([
                'form.email' => $this->deactivatedMessage($user->status),
            ]);
        }
    }

    protected function deactivatedMessage(string $status): string
    {
        $reason = $status === 'suspended'
            ? 'Your account has been suspended.'
            : 'Your account is inactive.';

        $contact = Setting::get('org_contact_email');

        return $contact
            ? "{$reason} Please contact your administrator at {$contact} for assistance."
            : "{$reason} Please contact your administrator for assistance.";
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
