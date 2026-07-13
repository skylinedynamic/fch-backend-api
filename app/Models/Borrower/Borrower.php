<?php

namespace App\Models\Borrower;

use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\Invoice\Invoice;
use App\Models\Subscription\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;


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
        'partner_payment_for_me' => 'boolean',
        'is_free_trail' => 'boolean',
        'free_trail_end_date' => 'date',
        'credit_profile_updated' => 'boolean',
    ];

    const STATUS_ONBOARDING = 'OnBoarding';
    const STATUS_ACTIVE = 'Active';
    const STATUS_SUSPENDED = 'Suspended';
    const STATUS_INACTIVE = 'Inactive';


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
            self::STATUS_INACTIVE,
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

    public function borrowerDocuments()
    {
        return $this->hasMany(BorrowerDocument::class, 'borrower_id');
    }

    /**
     * Get the signed agreement for the borrower.
     */
    public function signedAgreement(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\App\Models\Common\AgreementSign::class, 'entity');
    }

    /**
     * Get all subscriptions for the borrower.
     */
    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Subscription::class, 'entity');
    }

    /**
     * Check if the borrower is deletable based on invoice status.
     */
    public function isDeletable(): bool
    {
        // Check if there are any Paid or Successful invoices
        return !$this->invoices()
            ->whereIn('status', [Invoice::STATUS_PAID])
            ->exists();
    }

    /**
     * Delete borrower and all related data ONLY if no paid invoices exist.
     */
    public function safeDeleteWithRelations(): bool
    {
        if (!$this->isDeletable()) {
            throw new \Exception("This record cannot be deleted. Please mark it as Suspended or Inactive instead.");
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $isAlreadyCascading = \App\Models\User\User::$isCascading;
            \App\Models\User\User::$isCascading = true;

            // 1. Delete associated User recursively (only if not already cascading)
            if (!$isAlreadyCascading && $this->user) {
                // Use safeDelete to clean up user's own relations if any
                $this->user->safeDeleteWithRelations();
            }

            // 2. Delete Documents
            BorrowerDocument::where('borrower_id', $this->id)->delete();

            // 3. Delete Subscriptions & Phases
            $this->subscriptions()->each(function ($sub) {
                $sub->allPhases()->delete();
                $sub->delete();
            });

            // 4. Delete Invoices (Unpaid only - double checked for safety)
            $this->invoices()
                ->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_SUCCESS])
                ->delete();

            // 5. Delete other relations
            $this->fundingApplications()->delete();
            $this->preQualificationRequests()->delete();
            $this->verificationRequests()->delete();

            // 6. Finally Delete Borrower record
            $this->delete();

            \Illuminate\Support\Facades\DB::commit();
            \App\Models\User\User::$isCascading = $isAlreadyCascading;
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \App\Models\User\User::$isCascading = $isAlreadyCascading;
            throw $e;
        }
    }

    public function creditProfile()
    {
        return $this->hasOne(\App\Models\Borrower\BorrowerCreditProfile::class, 'borrower_id');
    }

    public function bankAccounts()
    {
        return $this->hasMany(\App\Models\Borrower\BorrowerBankAccount::class, 'borrower_id');
    }

    public function creditProfileRequests()
    {
        return $this->hasMany(\App\Models\Borrower\CreditProfileRequest::class, 'borrower_id');
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
                ->orWhere('borrowers.business_name', 'LIKE', "%{$search}%")
                ->orWhereHas(
                    'partner',
                    function ($q) use ($search) {
                        $q->where('full_name', 'LIKE', "%{$search}%");
                    }
                )
                ->orWhereHas(
                    'tier',
                    function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    }
                )
                ->orWhereHas(
                    'assignedUser',
                    function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    }
                )
                ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
        });
    }


    public function getEntityImage(): string
    {
        return $this->image ?? asset('avatar/avatar-default.jpg');
    }

    public function getImageAttribute($value)
    {
        return $value ? encodeUrl(asset("storage/{$value}")) : null;
    }

    public function getYearsInBusinessAttribute()
    {
        if ($this->business_start_date) {
            $now = Carbon::now();
            $start = Carbon::parse($this->business_start_date);
            
            // Decimal years, rounded to 1 decimal place
            $diffInDays = $start->diffInDays($now);
            return round($diffInDays / 365.25, 1);
        }
        
        return 0;
    }
}

