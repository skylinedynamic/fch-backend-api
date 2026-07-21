<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Business\Business;
use App\Models\Employee\Employee;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Commission\Commission;
use App\Models\Lead\Lead;
use App\Models\Partner\PartnerDocument;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

use App\Models\Subscription\Subscription;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;

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
        'is_host',
        'has_sign',
        'full_name',
        'company_name',
        'email',
        'phone_number',
        'contact_person',
        'contact_person_email',
        'contact_person_phone_number',
        'contact_person_relation',
        'partner_type',
        'status',
        'state',
        'city',
        'postal_code',
        'address_line_1',
        'address_line_2',
        'country',
        'country_code',
        'joining_date',
        'company_logo',
        'assign_to',
        'partner_image',
        'tax_id',
        'website',
        'active_custom_url',
        'partner_domain',
        'borrower_domain',
        'partner_subdomain',
        'total_free_users',
        'total_paid_users',
        'is_free_trail',
        'free_trail_end_date',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_host' => 'boolean',
        'has_sign' => 'boolean',
        'active_custom_url' => 'boolean',
        'is_free_trail' => 'boolean',
        'free_trail_end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public const STATUS = [
        'Active' => 'Active',
        'Inactive' => 'Inactive',
        'Suspended' => 'Suspended',
        'OnBoarding' => 'OnBoarding',
    ];

    public const RESTRICTED_DOMAINS = [
        'localhost',
        'test',
        'staging',
        'production',
        'partner',
        'borrower',
        'admin',
        'auth',
        'notifications',
        'common',
        'filemanager',
        '2fa',
        'api',
        'website',
        'client'
    ];

    const STATUS_ONBOARDING = 'OnBoarding';
    const STATUS_ACTIVE = 'Active';
    const STATUS_SUSPENDED = 'Suspended';
    const STATUS_INACTIVE = 'Inactive';

    const PARTNER_TYPE_CERTIFIED = 'Certified Partner';
    const PARTNER_TYPE_WHITE_LABEL = 'White-Label Partner (WL)';

    /**
     * Users that belong to this partner (via users.partner_id).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'partner_id');
    }

    public static function host()
    {
        return static::where('is_host', true)->first();
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

    /**
     * Scope: apply text search across common partner fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('company_name', 'LIKE', "%{$search}%")
                ->orWhere('email', 'LIKE', "%{$search}%")
                ->orWhere('phone_number', 'LIKE', "%{$search}%")
                ->orWhere('full_name', 'LIKE', "%{$search}%")
                ->orWhere('contact_person', 'LIKE', "%{$search}%")
                ->orWhere('contact_person_email', 'LIKE', "%{$search}%")
                ->orWhere('contact_person_phone_number', 'LIKE', "%{$search}%")
                ->orWhere('partner_type', 'LIKE', "%{$search}%")
                ->orWhere('status', 'LIKE', "%{$search}%")
                ->orWhereHas('assignedUser', function ($userQ) use ($search) {
                    $userQ->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%");
                });
        });
    }
    /**
     * Get the borrowers for the partner.
     */
    public function borrowers(): HasMany
    {
        return $this->hasMany(Borrower::class, 'partner_id');
    }

    /**
     * Get the documents for the partner.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PartnerDocument::class, 'partner_id');
    }

    /**
     * Get the subscription for the partner.
     */
    /**
     * Get the subscription for the partner (latest).
     */
    public function subscription(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(Subscription::class, 'entity')->latestOfMany();
    }

    /**
     * Get the saved payment methods for the partner.
     */
    public function savedPaymentMethods(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Models\Payment\SavedPaymentMethod::class, 'entity');
    }

    /**
     * Get all subscriptions for the partner.
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'entity');
    }

    // /**
    //  * Get the company logo URL.
    //  */
    // public function getCompanyLogoAttribute($value)
    // {
    //     return $value ? asset("storage/partners/{$this->id}/{$value}") : null;
    // }

    /**
     * Get the partner image URL.
     */
    public function getPartnerImageAttribute($value)
    {
        return $value ? encodeUrl(asset("storage/{$value}")) : null;
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'partner_id');
    }


    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'partner_id');
    }

    public function fundingApplications()
    {
        return $this->hasMany(FundingApplication::class, 'partner_id');
    }

    public function preQualificationRequests()
    {
        return $this->hasMany(PreQualificationRequest::class, 'partner_id');
    }

    /**
     * Get the invoices for the partner.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(\App\Models\Invoice\Invoice::class, 'partner_id');
    }

    /**
     * Check if the partner is deletable based on invoice status.
     */
    public function isDeletable(): bool
    {
        // Check if there are any Paid or Successful invoices for this partner
        return !$this->invoices()
            ->whereIn('status', [\App\Models\Invoice\Invoice::STATUS_PAID])
            ->exists();
    }

    /**
     * Delete partner and all related data ONLY if no paid invoices exist.
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

            // 1. Delete Borrowers and their related data recursively
            $this->borrowers->each(function ($borrower) {
                $borrower->safeDeleteWithRelations();
            });

            // 2. Delete Users directly linked to partner_id recursively 
            // (Only if not already cascading FROM a user)
            if (!$isAlreadyCascading) {
                // Check if we have a primary user for this entity
                if ($this->partnerUser) {
                    $this->partnerUser->safeDeleteWithRelations();
                }

                // Delete other users linked to this partner
                $this->users->each(function ($user) {
                    $user->safeDeleteWithRelations();
                });
            }

            // 3. Delete Subscriptions & Phases
            $this->subscriptions()->each(function ($sub) {
                $sub->allPhases()->delete();
                $sub->delete();
            });

            // 4. Delete Invoices (Unpaid only - double checked for safety)
            $this->invoices()
                ->whereNotIn('status', [\App\Models\Invoice\Invoice::STATUS_PAID, \App\Models\Invoice\Invoice::STATUS_SUCCESS])
                ->delete();

            // 5. Delete other related tables
            $this->leads()->delete();
            $this->commissions()->delete();
            $this->fundingApplications()->delete();
            $this->preQualificationRequests()->delete();
            $this->documents()->delete();

            // Delete Roles linked to partner
            \App\Models\Common\Role::where('partner_id', $this->id)->delete();

            // 6. Finally Delete Partner record
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


    public function getEntityImage(): string
    {
        return $this->partner_image ?? asset('avatar/avatar-default.jpg');
    }
}
