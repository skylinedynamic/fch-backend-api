<?php

namespace App\Models\Company;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies';

    // Status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUSPENDED = 'suspended';

    // Array of all status values for validation
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_PENDING,
        self::STATUS_SUSPENDED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'company_ein',
        'company_email',
        'company_phone',
        'company_website',
        'company_entity_type',
        'company_type',
        'company_description',
        // Company address
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'company_logo',
        'status',
        'business_registration_number',
        'tax_identification_number',
        'assigned_agent_id',
        'additional_data',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'additional_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Get the user that owns the company.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the assigned agent for the company.
     */
    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /**
     * Scope a query to only include active companies.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope a query to only include inactive companies.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    /**
     * Scope a query to only include pending companies.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include suspended companies.
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    /**
     * Get the full address attribute.
     */
    public function getFullAddressAttribute()
    {
        $address = collect([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ])->filter()->implode(', ');

        return $address ?: null;
    }

    /**
     * Get the company logo URL.
     */
    public function getLogoUrlAttribute()
    {
        return $this->company_logo ? asset('storage/' . $this->company_logo) : null;
    }

    /**
     * Check if the company is active.
     */
    public function isActive()
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the company is inactive.
     */
    public function isInactive()
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    /**
     * Check if the company is pending.
     */
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the company is suspended.
     */
    public function isSuspended()
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Activate the company.
     */
    public function activate()
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    /**
     * Deactivate the company.
     */
    public function deactivate()
    {
        $this->update(['status' => self::STATUS_INACTIVE]);
    }

    /**
     * Suspend the company.
     */
    public function suspend()
    {
        $this->update(['status' => self::STATUS_SUSPENDED]);
    }

    /**
     * Set company status to pending.
     */
    public function setPending()
    {
        $this->update(['status' => self::STATUS_PENDING]);
    }
}