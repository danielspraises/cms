<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'society_id', 'role', 'member_uid', 'full_name', 'email', 'phone', 'password', 'status',
    ];

    protected $hidden = ['password', 'remember_token'];

    public function society(): BelongsTo
    {
        return $this->belongsTo(Society::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function deductionChannels(): HasMany
    {
        return $this->hasMany(DeductionChannel::class, 'member_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'member_id');
    }

    public function isSocietyAdmin(): bool
    {
        return $this->role === 'society_admin';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * The single source of truth for "can this user act on society X" —
     * platform_admin can act on any society (§9.1/§9.2 of the blueprint —
     * the society-switcher model), everyone else only their own. Every
     * controller that authorizes a request against a specific resource's
     * society_id should go through this rather than re-deriving the logic.
     */
    public function canActOnSociety(int $societyId): bool
    {
        return $this->role === 'platform_admin' || $this->society_id === $societyId;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('assigned_by', 'assigned_at');
    }

    /**
     * Fine-grained permission check, e.g. $user->hasPermission('ledger.edit').
     * platform_admin and society_admin get an implicit all-access pass for
     * their own society — the roles/permissions tables exist for everyone
     * ELSE (auditors, loan officers, custom staff roles the society admin
     * defines), not to gate the admins themselves.
     *
     * Cached per-request (not cross-request) since a user's permission set
     * doesn't change mid-request and this can get called many times per
     * page (e.g. rendering a nav menu).
     */
    public function hasPermission(string $key): bool
    {
        if ($this->role === 'platform_admin') {
            return true;
        }

        if ($this->role === 'society_admin') {
            return true; // full control over their own society, see EnsureSocietyScope
        }

        return in_array($key, $this->cachedPermissionKeys(), true);
    }

    private function cachedPermissionKeys(): array
    {
        return Cache::remember(
            "user:{$this->id}:permissions",
            now()->addMinutes(5),
            fn () => $this->roles()->with('permissions')->get()
                ->flatMap(fn (Role $role) => $role->permissions->pluck('key'))
                ->unique()
                ->values()
                ->all()
        );
    }

    /**
     * Convenience accessor: get or lazily create the account row for a fund type.
     * Replaces the old pattern of "SELECT ... ORDER BY entry_date DESC LIMIT 1"
     * to find the last known balance.
     */
    public function account(string $accountType): Account
    {
        return $this->accounts()->firstOrCreate(['account_type' => $accountType]);
    }
}
