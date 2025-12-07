<?php

namespace App\Models\Common;

use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
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
