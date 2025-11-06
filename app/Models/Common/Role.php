<?php

namespace App\Models\Common;

use App\Models\Business\Business;
use App\Models\Employee\Employee;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'business_id'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
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
