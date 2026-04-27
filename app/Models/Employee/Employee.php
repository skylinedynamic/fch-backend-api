<?php

namespace App\Models\Employee;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'employees';

    protected $fillable = [
        'partner_id',
        'first_name',
        'last_name',
        'email',
        'phone_number_1',
        'phone_number_2',
        'date_of_birth',
        'gender',
        'city',
        'state',
        'postal_code',
        'country',
        'address',
        'image',
        'designation',
        'department',
        'job_status',
        'joining_date',
        'emergency_contact',
        'emergency_phone',
        'employee_code',
        'country_code',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';
    const STATUS_SUSPENDED = 'Suspended';

    /**
     * 🔹 Relationship: each employee belongs to a user.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'entity_id')
            ->where('entity_type', Employee::class);
    }

    /**
     * 🔹 Full name accessor.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Scope: apply text search across common employee fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (! is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone_number_1', 'like', "%{$search}%")
                ->orWhere('department', 'like', "%{$search}%")
                ->orWhere('designation', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%");
        });
    }

    public static function generateCode($partnerId)
    {
        $lastEmployee = self::where('partner_id', $partnerId)
            ->orderBy('id', 'DESC')
            ->first();

        if (! $lastEmployee || empty($lastEmployee->employee_code)) {
            return 'EMP-00001';
        }

        $number = (int) str_replace('EMP-', '', $lastEmployee->employee_code);

        return 'EMP-' . str_pad($number + 1, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Get the employee image URL.
     */
    public function getImageAttribute($value)
    {
        return $value ? encodeUrl(asset("storage/{$value}")) : null;
    }

    public function getEntityImage(): string
    {
        return $this->image ?? asset('avatar/avatar-default.jpg');
    }
}
