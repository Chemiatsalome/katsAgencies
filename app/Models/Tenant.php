<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'user_id', 'name', 'phone', 'email', 'id_number',
    'emergency_contact_name', 'emergency_contact_phone', 'payment_due_day', 'notes',
    'portal_invite_token', 'portal_invite_sent_at',
])]
class Tenant extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'portal_invite_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // documents() is polymorphic — there's no DB-level FK to cascade
        // this, and Document's own deleting hook (which removes the
        // physical file) only fires per-row through Eloquent, not via a
        // parent hard delete, so it has to be triggered explicitly here.
        static::deleting(function (Tenant $tenant) {
            $tenant->documents->each->delete();

            // Every one of this tenant's tenancies — and everything hung
            // off them (notices, change/renewal requests, etc.) — is about
            // to be hard-deleted by the tenancies.tenant_id cascade. But
            // AppNotification's related_type/related_id is polymorphic
            // with no FK of its own, so those rows would otherwise survive
            // pointing at nothing. AppNotification::actionUrl() rendering
            // one of those (notifiable's own bell, or any landlord's) is
            // exactly what crashed the whole dashboard the first time this
            // happened — this keeps new dangling rows from piling up again.
            $tenancyIds = $tenant->tenancies()->pluck('id');

            foreach ([
                Tenancy::class,
                TenancyNotice::class,
                TenancyChangeRequest::class,
                TenancyRenewalRequest::class,
                DueDayChangeRequest::class,
                LateFeeWaiverRequest::class,
            ] as $model) {
                $ids = $model === Tenancy::class
                    ? $tenancyIds
                    : $model::whereIn('tenancy_id', $tenancyIds)->pluck('id');

                AppNotification::where('related_type', $model)->whereIn('related_id', $ids)->delete();
            }

            // The tenant's own notifications (addressed to them directly)
            // are equally orphaned once they're gone.
            AppNotification::where('notifiable_type', $tenant->getMorphClass())
                ->where('notifiable_id', $tenant->id)
                ->delete();
        });
    }

    /** Has an actual login — i.e. has completed portal setup at least once. */
    public function hasPortalAccess(): bool
    {
        return $this->user_id !== null;
    }

    /** Invited but hasn't finished setting up their portal account yet. */
    public function isPortalInvited(): bool
    {
        return ! $this->hasPortalAccess() && $this->portal_invite_token !== null;
    }

    /**
     * The most recent entry in the free-text `notes` field, for compact
     * display (e.g. an Arrears table row) — split out from the raw blob
     * rather than shown as-is, since `notes` is a single growing log of
     * every note ever added (see Arrears\Index::addNote()), each appended
     * on its own line as "[date time] Arrears note: text". Older/imported
     * notes have no such prefix at all, so this tolerates both: the date
     * comes back separately when present, and the "Arrears note:"
     * boilerplate is always stripped since the column it's shown in is
     * already labeled "Notes".
     *
     * @return array{date: ?string, text: string}|null
     */
    public function latestNoteEntry(): ?array
    {
        if (! $this->notes) {
            return null;
        }

        $lastLine = trim(collect(explode("\n", trim($this->notes)))->last());

        if ($lastLine === '') {
            return null;
        }

        if (preg_match('/^\[(.+?)\]\s*Arrears note:\s*(.*)$/s', $lastLine, $matches)) {
            return ['date' => $matches[1], 'text' => $matches[2]];
        }

        return ['date' => null, 'text' => $lastLine];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    /**
     * The tenant's single currently-occupied tenancy, if any. Ending a
     * tenancy and immediately re-adding the same tenant can leave two rows
     * sharing the exact same move_in_date (same-day re-add) — ordering by
     * that alone is non-deterministic on a tie, so `id` breaks it in favor
     * of whichever row was actually created most recently. Every "what's
     * this tenant's current tenancy" lookup across the tenant portal
     * should go through this one method rather than re-deriving its own
     * slightly different version of the same query.
     */
    public function currentTenancy(): ?Tenancy
    {
        return $this->tenancies()->active()->latest('move_in_date')->latest('id')->first();
    }

    /**
     * The tenant's most recent tenancy regardless of status — for the few
     * places a tenant still needs access after moving out (e.g. tracking a
     * deposit refund). Same tie-break as currentTenancy(), for the same
     * reason.
     */
    public function mostRecentTenancy(): ?Tenancy
    {
        return $this->tenancies()->latest('move_in_date')->latest('id')->first();
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(AppNotification::class, 'notifiable');
    }

    /** A tenant is accessible if any of their tenancies sits under an accessible property. */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenancies', fn (Builder $q) => $q->accessibleBy($user));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'phone', 'email', 'payment_due_day'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
