<?php

namespace App\Models\Employee;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Business\Business;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'employees';

    protected $fillable = [
        'user_id',
        'business_id',
        'first_name',   
        'last_name',
        'email',
        'phone_number',
        'phone_number_2',
        'date_of_birth',
        'gender',
        'city',
        'state',
        'postal_code',
        'country',
        'address',
        'ssn',
        'image',
        'designation',
        'department', 
        'job_status',
        'joining_date',
        'identification_number',
        'blood_group', 
        'place_of_birth',
        'emergency_contact',
        'emergency_phone', 
        'marital_status', 
        'no_of_children',
        'address'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 🔹 Relationship: each employee belongs to a user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 🔹 Relationship: each employee belongs to a business/company.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get partners associated with this employee.
     */
    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    /**
     * Get borrowers associated with this employee.
     */
    public function borrowers(): HasMany
    {
        return $this->hasMany(Borrower::class);
    }

    /**
     * 🔹 Full name accessor.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
