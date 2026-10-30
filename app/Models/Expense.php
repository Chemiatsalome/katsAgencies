<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'property_id', 'block_id', 'house_id', 'tenancy_id', 'expense_category_id',
    'vendor', 'amount', 'expense_date', 'description', 'recorded_by', 'deposit_deduction_id',
])]
class Expense extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        // Once an expense has funded a deposit deduction, deleting it would
        // leave that deduction's "what was this actually for" pointing at
        // nothing — the financial record it's justified by would just
        // vanish. The correct correction path is reversing the deduction
        // itself (DepositService::voidDeduction, via a landlord-recorded
        // reason), which keeps both rows on the audit trail; this only
        // blocks the expense side from being deleted out from under it.
        static::deleting(function (Expense $expense) {
            if ($expense->deposit_deduction_id) {
                return false;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * §8 (design approved 2026-10-25): nullable — copied forward from
     * MaintenanceTask::tenancy_id when this expense is generated from a
     * task (MaintenanceCostService::recordExpenseFor()/
     * recordAdditionalExpenseFor()). NULL for a landlord's direct manual
     * entry (Expenses\Form), which has no tenancy context at all. An
     * occurrence/association fact only — never on its own grounds for
     * tenant liability (see TenantLiabilityDecisionService, §7).
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function depositDeduction(): BelongsTo
    {
        return $this->belongsTo(DepositDeduction::class);
    }

    /** The maintenance task this expense was auto-generated from, if any (see MaintenanceCostService::recordExpenseFor). */
    public function maintenanceTask(): HasOne
    {
        return $this->hasOne(MaintenanceTask::class, 'expense_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['property_id', 'expense_category_id', 'vendor', 'amount', 'expense_date', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
