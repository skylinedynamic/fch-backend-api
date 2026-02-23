<?php

namespace App\Models\Borrower;

use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\Invoice\Invoice;
use App\Models\CreditBuilder\Tier;
use Illuminate\Database\Eloquent\Model;
use App\Models\Subscription\Subscription;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Borrower extends Model
{
    use HasFactory, SoftDeletes;

    protected string $guard_name = 'api';
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'borrowers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

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

    const STATUS_ONBOARDING = 'OnBoarding';
    const STATUS_ACTIVE = 'Active';
    const STATUS_SUSPENDED = 'Suspended';
    const STATUS_CLOSED = 'Closed';


    /**
     * Get the user that owns the borrower.
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\App\Models\User\User::class, 'entity');
    }

    /**
     * Get the partner that owns the borrower.
     */
    public function partner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Partner\Partner::class, 'partner_id');
    }

    /**
     * Get the borrower's full name.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get the assigned user for the borrower.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User\User::class, 'assign_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'current_tier');
    }

    public function verificationRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BorrowerTaskVerificationRequest::class, 'borrower_id');
    }

    /**
     * Get the invoices for the borrower.
     */
    public function invoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Invoice::class, 'borrower_id');
    }

    public function taskUnderReview(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BorrowerTaskVerificationRequest::class, 'borrower_id')->where('status', BorrowerTaskVerificationRequest::PROCESSING);
    }

    /**
     * Get the latest invoice for the borrower.
     */
    public function latestInvoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Invoice::class, 'borrower_id')->latestOfMany();
    }

    /**
     * Get the subscription for the borrower.
     */
    public function subscription(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(Subscription::class, 'entity');
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_ONBOARDING,
            self::STATUS_ACTIVE,
            self::STATUS_SUSPENDED,
            self::STATUS_CLOSED
        ];
    }

    public function latestSubscription()
    {
        return $this->morphOne(Subscription::class, 'entity')->latestOfMany();
    }

    public function fundingApplications()
    {
        return $this->hasMany(FundingApplication::class, 'borrower_id');
    }

    public function preQualificationRequests()
    {
        return $this->hasMany(PreQualificationRequest::class, 'borrower_id');
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('borrowers.first_name', 'LIKE', "%{$search}%")
                ->orWhere('borrowers.last_name', 'LIKE', "%{$search}%")
                ->orWhere('borrowers.email', 'LIKE', "%{$search}%")
                ->orWhere('borrowers.phone_number', 'LIKE', "%{$search}%")
                ->orWhere('borrowers.status', 'LIKE', "%{$search}%")
                ->orWhereHas('partner', function ($q) use ($search) {
                    $q->where('full_name', 'LIKE', "%{$search}%");
                })
                ->orWhereHas('tier', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%");
                })
                ->orWhereHas('assignedUser', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%");
                })
                ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
        });
    }
}
