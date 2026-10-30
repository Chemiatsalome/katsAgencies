<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Rent obligations are derived from active tenancies — keep the
        // current period's charges generated ahead of when they're due,
        // rather than relying on a landlord viewing a page to trigger it.
        $schedule->command('rent:generate-charges')->dailyAt('00:05');
        $schedule->command('rent:apply-late-fees')->dailyAt('00:30');

        // Catches an M-Pesa transaction whose callback never arrived at all
        // — the only real reconciliation gap in the whole payment flow.
        // Every five minutes rather than daily: a tenant waiting to find
        // out whether their rent payment went through shouldn't wait a day.
        $schedule->command('mpesa:reconcile-pending')->everyFiveMinutes();

        $schedule->command('notifications:rent-reminders')->dailyAt('07:00');
        $schedule->command('notifications:contract-expiry')->dailyAt('07:15');
        $schedule->command('notifications:renewal-reminders')->dailyAt('07:30');
        $schedule->command('notifications:disbursements-overdue')->dailyAt('08:00');
        $schedule->command('tenant-invitations:expire')->hourly();
        $schedule->command('maintenance:generate-recurring-tasks')->dailyAt('06:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\EnsureAccountIsActive::class,
        ]);

        // Safaricom posts here with no CSRF token or session of ours.
        $middleware->validateCsrfTokens(except: [
            'mpesa/callback/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
