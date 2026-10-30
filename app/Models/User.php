<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'email', 'password', 'phone', 'status', 'approved_at',
    'national_id', 'business_name', 'property_location', 'intended_unit_count',
    'email_verified_at',
])]
#[Hidden(['password', 'remember_token', 'national_id'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, LogsActivity;

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'landlord_id');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class, 'landlord_id');
    }

    public function tenant(): HasOne
    {
        return $this->hasOne(Tenant::class);
    }

    public function assignedComplaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'assigned_to');
    }

    /** The manager relationship this user account was created from, if this user is a manager. */
    public function manager(): HasOne
    {
        return $this->hasOne(Manager::class, 'user_id');
    }

    /** Managers this user (as a landlord) has delegated properties to. */
    public function managers(): HasMany
    {
        return $this->hasMany(Manager::class, 'landlord_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Landlords that have actually been through approval at some point —
     * active or suspended, both still real accounts with real history.
     * Excludes only applicants still sitting in the Applications queue,
     * who inevitably show up with zero properties/zero everything in any
     * "by landlord" breakdown or report-generation picker, which reads as
     * noise (or looks like a duplicate row) rather than useful data.
     */
    public function scopeApprovedLandlords(Builder $query): Builder
    {
        return $query->role('landlord')->whereNotNull('approved_at');
    }

    /**
     * A fresh signup still awaiting its first admin review — as opposed to
     * an account that was active at some point and has since been
     * deactivated/suspended, which looks identical via `status` alone.
     */
    public function isPendingApproval(): bool
    {
        return $this->status === 'inactive' && $this->approved_at === null;
    }

    /**
     * The landlord whose data this user should see — themselves if they are a
     * landlord, the landlord they've been delegated to if they're a manager,
     * or null (unscoped, e.g. admin) otherwise. Preserves the existing
     * "null = unscoped" convention already used by RentScheduleService.
     */
    public function landlordId(): ?int
    {
        if ($this->hasRole('landlord')) {
            return $this->id;
        }

        if ($this->hasRole('manager')) {
            return $this->manager?->landlord_id;
        }

        return null;
    }

    public function isManager(): bool
    {
        return $this->hasRole('manager');
    }

    /**
     * The property IDs this user is restricted to, or null when unrestricted
     * (a landlord/admin, or a manager granted "all properties"). Callers
     * should treat null as "no additional filter needed", not "no access".
     */
    public function accessiblePropertyIds(): ?Collection
    {
        if (! $this->hasRole('manager')) {
            return null;
        }

        $manager = $this->manager;

        if (! $manager || $manager->all_properties) {
            return null;
        }

        return $manager->properties()->pluck('properties.id');
    }

    /**
     * Whether this user is allowed to perform a manager-gated action. A
     * landlord/admin is never permission-restricted here (they're only ever
     * restricted to their own data) — this only meaningfully restricts a
     * manager, via whatever Spatie permissions the landlord granted them.
     */
    public function hasManagerPermission(string $permission): bool
    {
        if (! $this->hasRole('manager')) {
            return true;
        }

        return $this->can($permission);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
            'intended_unit_count' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
