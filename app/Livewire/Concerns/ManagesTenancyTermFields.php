<?php

namespace App\Livewire\Concerns;

use Carbon\Carbon;

/**
 * Deposit-preset buttons and the month-based notice-period picker (plus
 * its human-readable "vacate by" preview) — identical UI logic behind both
 * Add Tenant (Tenancies\Form) and Invite Tenant (TenantInvitations\Create),
 * since an invitation can equally represent a deposit the landlord already
 * collected in cash before onboarding the tenant digitally.
 *
 * Expects the using component to declare: $monthly_rent, $deposit_required,
 * $initial_deposit_paid, $move_in_date, $notice_period_days (all string).
 */
trait ManagesTenancyTermFields
{
    public string $notice_period_months = '';

    public string $deposit_option = 'none';

    public function updatedMonthlyRent(): void
    {
        $this->recalculateDepositFromOption();
    }

    public function updatedDepositRequired(): void
    {
        $this->recalculateDepositFromOption();
    }

    public function updatedDepositOption(): void
    {
        $this->recalculateDepositFromOption();
    }

    public function updatedMoveInDate(): void
    {
        $this->recalculateNoticeDaysFromMonths();
    }

    public function updatedNoticePeriodMonths(): void
    {
        $this->recalculateNoticeDaysFromMonths();
    }

    /**
     * Keeps initial_deposit_paid in sync with whichever preset is selected,
     * recomputed against the unit's actual monthly_rent/deposit_required —
     * never a hard-coded figure. 'custom' is left alone: that's the
     * landlord typing their own exact amount.
     */
    private function recalculateDepositFromOption(): void
    {
        $rent = (float) ($this->monthly_rent ?: 0);
        $required = (float) ($this->deposit_required ?: 0);

        $this->initial_deposit_paid = match ($this->deposit_option) {
            'none' => '0',
            'one_month' => (string) min($rent, $required),
            'two_months' => (string) min($rent * 2, $required),
            'full' => (string) $required,
            default => $this->initial_deposit_paid,
        };
    }

    /**
     * Presets offered for "deposit paid" depend on the unit's actual
     * configuration — not every property requires two months' deposit, so
     * the one/two-month presets only appear when they're genuinely a
     * distinct, smaller amount than the full required deposit.
     *
     * @return array<int, array{key: string, label: string, amount: float|null}>
     */
    public function depositPresetOptions(): array
    {
        $rent = (float) ($this->monthly_rent ?: 0);
        $required = (float) ($this->deposit_required ?: 0);

        $options = [
            ['key' => 'none', 'label' => 'No deposit paid', 'amount' => 0.0],
        ];

        if ($rent > 0 && $rent < $required) {
            $options[] = ['key' => 'one_month', 'label' => "One month's rent", 'amount' => $rent];
        }

        if ($rent > 0 && $rent * 2 < $required) {
            $options[] = ['key' => 'two_months', 'label' => "Two months' rent", 'amount' => $rent * 2];
        }

        $options[] = ['key' => 'full', 'label' => 'Deposit paid in full', 'amount' => $required];
        $options[] = ['key' => 'custom', 'label' => 'Partial / custom amount', 'amount' => null];

        return $options;
    }

    /**
     * Converts the landlord's month-based notice period into the stored
     * day count using real calendar arithmetic anchored on the actual
     * move-in date (addMonthsNoOverflow) — never a flat "1 month = 30
     * days" guess, which under- or over-counts depending which months the
     * notice period spans. Left untouched for '' (not specified) and
     * 'custom' (the landlord typing an exact day count themselves).
     */
    private function recalculateNoticeDaysFromMonths(): void
    {
        if ($this->notice_period_months === '' || $this->notice_period_months === 'custom') {
            return;
        }

        $months = (int) $this->notice_period_months;
        $anchor = Carbon::parse($this->move_in_date ?: now());

        // diffInDays() is signed, not always-positive, by default — without
        // forcing absolute here, this came out negative (the later date
        // diffed against the earlier anchor), which then failed the
        // notice_period_days 'min:0' validation rule on save.
        $this->notice_period_days = (string) $anchor->diffInDays($anchor->copy()->addMonthsNoOverflow($months), true);
    }
}
