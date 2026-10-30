<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || $user->status === 'active') {
            return $next($request);
        }

        // The public marketing homepage carries no account data, so it stays
        // reachable regardless of status — otherwise a pending/deactivated
        // user has no way back to it short of logging out.
        if ($request->routeIs('home')) {
            return $next($request);
        }

        if ($user->status === 'inactive' && $user->hasRole('landlord')) {
            if ($request->routeIs('pending-approval') || $this->isLivewireRequestFrom($request, 'pending-approval')) {
                return $next($request);
            }

            return redirect()->route('pending-approval');
        }

        // Any other inactive account (manager deactivated by their landlord,
        // or a tenant/admin deactivated by a platform admin) has no
        // "pending review" state to land on like a fresh landlord signup
        // does — it just means access is off. This is a safety net for a
        // session that was active when an admin flipped the status; the
        // normal deactivated/suspended login attempt is caught earlier, in
        // LoginForm::authenticate().
        if ($user->status === 'inactive' || $user->status === 'suspended') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $reason = $user->status === 'suspended'
                ? 'Your account has been suspended.'
                : 'Your access has been deactivated.';

            $contact = Setting::get('org_contact_email');

            $message = $contact
                ? "{$reason} Please contact your administrator at {$contact} for assistance."
                : "{$reason} Please contact your administrator for assistance.";

            return redirect()->route('login')->withErrors([
                'form.email' => $message,
            ]);
        }

        return $next($request);
    }

    /**
     * Whether this is a Livewire component-update request (POST
     * /livewire/update) fired from a specific already-allowed page — e.g.
     * the "Log Out" button on the pending-approval page, which must still
     * be able to run its action rather than have this same middleware
     * intercept the update request before Livewire ever invokes it.
     *
     * The update endpoint is shared by every Livewire component in the app,
     * so it can't be allowed wholesale — that would let an already-mounted
     * component on a page this user should no longer have open keep
     * mutating data via AJAX after being deactivated. The Referer header
     * (which browsers set from same-origin fetch calls and JS cannot
     * override) scopes the exemption to just this one page.
     */
    protected function isLivewireRequestFrom(Request $request, string $routeName): bool
    {
        if (! $request->routeIs('default.livewire.update')) {
            return false;
        }

        $referer = (string) $request->headers->get('referer');

        return str_contains($referer, route($routeName, absolute: false));
    }
}
