<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Employee\Employee;
use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'businesses';

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
        'business_no',
        'business_name',
        'business_ein',
        'business_email',
        'business_phone',
        'business_website',
        'business_entity_type',
        'business_type',
        'business_description',
        'business_logo',
        'status',
        'business_registration_number',
        'tax_identification_number',
        'assign_id',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'additional_data',
        'duns_number',
        'state_file_number',
        'business_start_date',
        'notes',
        'currency',
        'years_in_business',
        'monthly_revenue',
        'annual_revenue',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'additional_data' => 'array',
        'business_start_date' => 'date',
        'monthly_revenue' => 'decimal:2',
        'annual_revenue' => 'decimal:2',
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
     * Get the user assigned to this business.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_id');
    }

    /**
     * Get employees associated with this business.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Get partners associated with this business.
     */
    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    /**
     * Get borrowers associated with this business.
     */
    public function borrowers(): HasMany
    {
        return $this->hasMany(Borrower::class);
    }
}