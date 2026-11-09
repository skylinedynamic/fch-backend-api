<?php

namespace App\Models\Employee;

use App\Models\Compensation\CompensationSetting;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\Files\FileUrl;

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

    const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED];

    /**
     * 🔹 Relationship: each employee belongs to a user.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'entity_id')
            ->where('entity_type', Employee::class);
    }

    /**
     * 🔹 Relationship: compensation rows — one for Hourly/Salary, one per
     * applicable service for Per Deal.
     */
    public function compensations(): MorphMany
    {
        return $this->morphMany(CompensationSetting::class, 'entity');
    }

    /**
     * Copy the details this employee shares with its login account (name,
     * email, phone) from that account, so editing either record keeps the
     * other in step. Only fields that differ are written.
     *
     * first_name holds the employee's whole name; last_name is a legacy column
     * that's no longer read or written.
     */
    public function syncFromUser(User $user): void
    {
        $this->fill([
            'first_name' => $user->name,
            'email' => $user->email,
            'phone_number_1' => $user->phone_number,
        ]);

        if ($this->isDirty()) {
            $this->save();
        }
    }

    /**
     * 🔹 Full name accessor — first_name holds the whole name (last_name is legacy).
     */
    public function getFullNameAttribute(): string
    {
        return trim((string) $this->first_name);
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
        return $value ? FileUrl::for($value) : null;
    }

    public function getEntityImage(): string
    {
        return $this->image ?? asset('avatar/avatar-default.jpg');
    }
}
