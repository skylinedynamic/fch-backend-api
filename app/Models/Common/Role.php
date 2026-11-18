<?php

namespace App\Models\Common;

use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /**
     * The reserved Developer role (RoleSeeder makes one per partner). Only a
     * Developer sees it, its members or its developer-only permissions, only a
     * Developer may hand it out, and no other role may take its name.
     */
    public const CODE_DEVELOPER = 'developer';
    public const NAME_DEVELOPER = 'Developer';

    /** True when $name would be the Developer role's name, in any case or padding. */
    public static function isReservedName(?string $name): bool
    {
        return strcasecmp(trim((string) $name), self::NAME_DEVELOPER) === 0;
    }

    /**
     * The one Developer role: the host's. A role coded "developer" under any
     * other partner (left from an old seed) is not it.
     */
    public function isDeveloper(): bool
    {
        return $this->code === self::CODE_DEVELOPER
            && (bool) Partner::whereKey($this->partner_id)->value('is_host');
    }

    /**
     * Whether $user may change this role. The Developer role is fixed for
     * everyone, a Developer included. Any other static (system) role - Admin -
     * only a Developer may change; a role that is not static, anyone allowed
     * to manage roles.
     */
    public function isEditableBy(User $user): bool
    {
        if ($this->code === self::CODE_DEVELOPER) {
            return false;
        }

        return !$this->is_static || $user->isDeveloper();
    }

    /** Limits a role query to the one Developer role - the host's. */
    public function scopeTheDeveloperRole($query)
    {
        return $query->where($this->qualifyColumn('code'), self::CODE_DEVELOPER)
            ->whereHas('partner', fn ($q) => $q->where('is_host', true));
    }

    /**
     * The roles $user may give to someone: its own partner's, and the
     * Developer role only when $user is a Developer.
     */
    public function scopeAssignableBy($query, User $user)
    {
        $query->where($this->qualifyColumn('partner_id'), $user->partner_id);

        if (!$user->isDeveloper()) {
            $query->where(fn ($q) => $q->whereNull($this->qualifyColumn('code'))
                ->orWhere($this->qualifyColumn('code'), '!=', self::CODE_DEVELOPER));
        }

        return $query;
    }

    public static function create(array $attributes = [])
    {
        $attributes['guard_name'] = $attributes['guard_name'] ?? 'api';
        $attributes['partner_id'] = $attributes['partner_id'] ?? null;

        $exists = static::where('name', $attributes['name'])
            ->where('guard_name', $attributes['guard_name'])
            ->where('partner_id', $attributes['partner_id'])
            ->exists();

        if ($exists) {
            throw new \Exception("A role `{$attributes['name']}` already exists for guard `{$attributes['guard_name']}` and partner `{$attributes['partner_id']}`.");
        }

        return static::query()->create($attributes);
    }

    protected $fillable = ['name', 'description', 'guard_name', 'partner_id', 'code', 'is_static'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * 🔹 All Users attached to this Role
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\User\User::class,
            'model_has_roles',
            'role_id',
            'model_id'
        )->where('model_type', \App\Models\User\User::class);
    }

    /**
     * 🔹 All Employees attached to this Role
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
            'model_has_roles',
            'role_id',
            'model_id'
        )->where('model_type', Employee::class);
    }
}
