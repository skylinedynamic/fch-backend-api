<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Business\Business;
use App\Models\Employee\Employee;
use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Partner extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'partners';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'employee_id',
        'status',
        'assign_to',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Users that belong to this partner (via users.partner_id).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'partner_id');
    }

    
    /**
     * Assigned user for this partner (via partners.assign_to).
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_to');
    }

    /**
     * The user that represents this Partner entity (polymorphic users.entity_type/id).
     */
    public function partnerUser(): MorphOne
    {
        return $this->morphOne(User::class, 'entity');
    }
}