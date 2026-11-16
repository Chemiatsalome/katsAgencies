<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Final account-lifecycle phase, Part A1: a suspended landlord's tenants
 * must lose access to normal portal functionality — not just the two
 * payment-initiation actions PayRent/PayDeposit already guard directly,
 * but every tenant page (dashboard, statements, contracts, maintenance,
 * messages, profile) and every Livewire action on them. Global, like
 * EnsureAccountIsActive/EnsurePasswordHasBeenChanged, so it also reaches
 * the shared Livewire update endpoint an already-open page's button click
 * posts to — a route-group middleware on tenant/* alone would never see
 * that shared endpoint at all.
 *
 * Scoped to Tenant::currentTenancyLandlordIsSuspended() specifically, not
 * a global tenant lockout — see that method's own docblock for why a
 * tenant's login can never safely be suspended wholesale here (one
 * User::tenant() hasOne, no way to represent "fine for landlord B, not
 * landlord A" if a login ever did span two Tenant rows).
 */
class EnsureTenantLandlordIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || ! $user->hasRole('tenant')) {
            return $next($request);
        }

        $tenant = $user->tenant;

        if (! $tenant || ! $tenant->currentTenancyLandlordIsSuspended()) {
            return $next($request);
        }

        // The notice page itself (and its own Livewire AJAX calls, same
        // Referer-matching technique as EnsurePasswordHasBeenChanged) stays
        // reachable — LogoutButton is rendered inside the notice page's own
        // layout, so its request's Referer already matches here too.
        if ($request->routeIs('tenant.account-restricted')
            || $this->isLivewireRequestFrom($request, 'tenant.account-restricted')) {
            return $next($request);
        }

        return redirect()->route('tenant.account-restricted');
    }

    /** Same technique as EnsureAccountIsActive::isLivewireRequestFrom() — see there for why. */
    protected function isLivewireRequestFrom(Request $request, string $routeName): bool
    {
        if (! $request->routeIs('default.livewire.update')) {
            return false;
        }

        $referer = (string) $request->headers->get('referer');

        return str_contains($referer, route($routeName, absolute: false));
    }
}
