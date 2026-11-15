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
use App\Models\Concerns\Archivable;
use App\Support\Branding\PartnerDomain;
use App\Support\Files\FileUrl;

class Partner extends Model
{
    use HasFactory, SoftDeletes, Archivable;



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
        'agreement_required',
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
        'is_free_trail',
        'free_trail_end_date',
        'stripe_customer_id',
        'default_payment_method',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_host' => 'boolean',
        'archived_at' => 'datetime',
        'has_sign' => 'boolean',
        'agreement_required' => 'boolean',
        'active_custom_url' => 'boolean',
        'is_free_trail' => 'boolean',
        'free_trail_end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'stripe_customer_id' => 'array',
    ];

    public const STATUS_ONBOARDING = 'OnBoarding';
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_SUSPENDED = 'Suspended';
    public const STATUS_INACTIVE = 'Inactive';


    public const STATUS = [
        self::STATUS_ACTIVE => self::STATUS_ACTIVE,
        self::STATUS_INACTIVE => self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED => self::STATUS_SUSPENDED,
        self::STATUS_ONBOARDING => self::STATUS_ONBOARDING,
    ];

    /**
     * A domain's cached partner is cleared when a partner takes, changes or
     * gives up a domain, and when it is deleted or restored (BUG-118).
     */
    protected static function booted(): void
    {
        static::saved(function (Partner $partner) {
            if ($partner->wasRecentlyCreated || $partner->wasChanged(PartnerDomain::COLUMNS)) {
                PartnerDomain::forgetPartner($partner);
            }
        });
        static::deleted(fn (Partner $partner) => PartnerDomain::forgetPartner($partner));
        static::restored(fn (Partner $partner) => PartnerDomain::forgetPartner($partner));
    }

    /**
     * Get raw Stripe Customer ID string from the JSON stripe_customer_id field.
     * Supports legacy plain string and new JSON {customer_id, name, card_no} format.
     */
    public function getStripeCustomerIdRawAttribute(): ?string
    {
        $value = $this->stripe_customer_id;
        if (is_array($value)) {
            return $value['customer_id'] ?? null;
        }
        return is_string($value) ? $value : null;
    }

    /**
     * Get formatted Stripe card display info from the JSON stripe_customer_id field.
     */
    public function getStripeCardInfoAttribute(): array
    {
        $value = $this->stripe_customer_id;
        if (is_array($value)) {
            return [
                'customer_id' => $value['customer_id'] ?? null,
                'name' => $value['name'] ?? null,
                'card_no' => $value['card_no'] ?? null,
            ];
        }
        return ['customer_id' => $value, 'name' => null, 'card_no' => null];
    }



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



    /**
     * The statuses allowed to sign in to the partner portal.
     *
     * An allowlist rather than a list of banned statuses. OnBoarding is where a
     * partner starts - PartnerService sets it for anyone not on a free trial -
     * so it belongs here; anything added to STATUS later is denied until it is
     * added here deliberately, and a null status is refused rather than let
     * through by a comparison that quietly returns false.
     */
    const LOGIN_ALLOWED_STATUSES = [
        self::STATUS_ONBOARDING,
        self::STATUS_ACTIVE,
    ];

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

    public const TRIAL_TRIAL = 'Trial';
    public const TRIAL_EXPIRED = 'Expired';
    public const TRIAL_REGULAR = 'Regular';

    /**
     * The trial states a partner's plan can be in, mirroring self::STATUS.
     *
     * These are statuses, not column values: Trial and Regular are resolved from
     * the is_free_trail flag, Expired additionally from the end date. Declared
     * here, next to the scope that interprets them, so the list screen never has
     * to guess the spelling - and so the filter can be built from this list
     * rather than re-typing the options.
     */
    public const TRIAL_STATUS = [
        self::TRIAL_TRIAL => self::TRIAL_TRIAL,
        self::TRIAL_EXPIRED => self::TRIAL_EXPIRED,
        self::TRIAL_REGULAR => self::TRIAL_REGULAR,
    ];

    /**
     * Scope: narrow to a trial status, as chosen in the Trials filter.
     *
     *   null / ''   -> no filter, every partner
     *   'Trial'     -> on a free trial, whether or not it has run out
     *   'Expired'   -> on a free trial whose end date has already passed
     *   'Regular'   -> not on a free trial
     *
     * Expired is a subset of Trial, not a sibling: a partner whose trial ran out
     * is still on a trial plan, so it appears under both.
     *
     * "Expired" deliberately matches the rule the list's own Trial badge uses: the
     * end date must be strictly before today, so a trial ending today still counts
     * as running. Anything looser would filter to rows the table labels "Trial".
     *
     * An unrecognised value filters nothing rather than matching arbitrarily - the
     * old code passed the raw value into a where(), where MySQL's loose comparison
     * silently made junk behave like "Regular".
     */
    public function scopeTrialFilter($query, $trial)
    {
        if ($trial === null || $trial === '') {
            return $query;
        }

        // Deprecated aliases. The filter used to send the is_free_trail boolean
        // straight through; mapped rather than ignored so a browser still running
        // the previous bundle keeps filtering correctly.
        if (in_array($trial, ['1', 1, true], true)) {
            $trial = self::TRIAL_TRIAL;
        } elseif (in_array($trial, ['0', 0, false], true)) {
            $trial = self::TRIAL_REGULAR;
        }

        $isFreeTrail = $this->qualifyColumn('is_free_trail');
        $endDate = $this->qualifyColumn('free_trail_end_date');

        return match ($trial) {
            self::TRIAL_TRIAL => $query->where($isFreeTrail, true),
            self::TRIAL_REGULAR => $query->where($isFreeTrail, false),
            self::TRIAL_EXPIRED => $query->where($isFreeTrail, true)
                ->whereNotNull($endDate)
                ->whereDate($endDate, '<', now()->toDateString()),
            default => $query,
        };
    }

    /**
     * Whether this partner's free trial has run out (R3-BUG-026): on a free
     * trial with an end date that is before today, in the caller's timezone.
     * The end date itself is still a trial day - the same boundary as the
     * Partners list's "Expired" filter and the partner portal's lock. The host
     * is never on a trial.
     */
    public function freeTrialExpired(): bool
    {
        if ((int) ($this->is_host ?? 0) === 1 || !$this->is_free_trail || !$this->free_trail_end_date) {
            return false;
        }

        return $this->free_trail_end_date->toDateString() < todayInRequestTimezone();
    }

    /**
     * The client portal this partner's clients sign in on and are linked to
     * (R3-BUG-032), or null when they have none:
     * - its own borrower_domain whenever it has one. Active Custom URL plays no
     *   part: it is the switch for the partner's own custom domain only.
     * - otherwise the host's shared client portal, but only for the host's and
     *   a Certified partner's clients.
     * - a White-Label partner's clients never get the host's portal: null.
     *
     * Sign-in (PortalIsolationService) accepts a client on exactly this domain.
     */
    public function borrowerPortalDomain(): ?string
    {
        if (filled($this->borrower_domain)) {
            return $this->borrower_domain;
        }

        if ($this->is_host || $this->partner_type === self::PARTNER_TYPE_CERTIFIED) {
            return config('app.frontend_borrower_url') ?: null;
        }

        return null;
    }

    /**
     * The partners the host manages: everyone but the host's own record. Used by
     * the partner count cards (dashboard and Partners list) so they agree.
     */
    public function scopeExcludingHost($query)
    {
        return $query->where($this->qualifyColumn('is_host'), false);
    }

    /**
     * Partners whose name or company contains the text - what a PARTNER NAME
     * column's filter is typed as.
     */
    public function scopeNamed($query, ?string $name)
    {
        if (!is_string($name) || trim($name) === '') {
            return $query;
        }

        $name = trim($name);

        return $query->where(function ($q) use ($name) {
            $q->whereContains('full_name', $name)
                ->orWhereContains('company_name', $name);
        });
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
            $q->whereContains('company_name', $search)
                ->orWhereContains('email', $search)
                ->orWhereContains('phone_number', $search)
                ->orWhereContains('full_name', $search)
                ->orWhereContains('contact_person', $search)
                ->orWhereContains('contact_person_email', $search)
                ->orWhereContains('contact_person_phone_number', $search)
                ->orWhereContains('partner_type', $search)
                ->orWhereContains('status', $search)
                ->orWhereHas('assignedUser', function ($userQ) use ($search) {
                    $userQ->whereContains('name', $search)
                        ->orWhereContains('email', $search);
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

    /** Seats a partner gets when neither its subscription nor Plan & Fee says. */
    public const DEFAULT_USER_SEATS = 3;

    /** Logins that take a seat: the partner's own and its employees'. */
    public function usedUserSeats(): int
    {
        return User::where('partner_id', $this->id)
            ->where(function ($query) {
                $query->whereIn('entity_type', [self::class, Employee::class])
                    ->orWhereNull('entity_type');
            })
            ->count();
    }

    /** The Plan & Fee "User Seat" plan for this partner's type, if any. */
    public function userSeatPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT)
            ->where('partner_type', $this->partner_type)
            ->active()
            ->first();
    }

    /**
     * How many logins the partner may have - the one rule for the Users
     * screen's count and the server's own check: its subscription's
     * user_seats, else the Plan & Fee seat plan's, else DEFAULT_USER_SEATS.
     */
    public function userSeatLimit(): int
    {
        $this->loadMissing('subscription');

        return (int) ($this->subscription?->user_seats
            ?? $this->userSeatPlan()?->total_seat_count
            ?? self::DEFAULT_USER_SEATS);
    }

    public function hasFreeUserSeat(): bool
    {
        return $this->usedUserSeats() < $this->userSeatLimit();
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
        return $value ? FileUrl::for($value) : null;
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
     * A partner can be deleted only while none of its invoices is paid (the
     * payment record must stay) and no borrowers or users are left under it.
     * Paid means the same as for a client: Paid or Success.
     */
    public function isDeletable(): bool
    {
        return !$this->invoices()
            ->whereIn('status', \App\Models\Borrower\Borrower::PAID_INVOICE_STATUSES)
            ->exists()
            && !$this->hasBorrowersOrUsers();
    }

    /** Why this partner cannot be deleted, and what to do instead. */
    public function notDeletableReason(): string
    {
        return __('partner.error.delete_blocked');
    }

    /**
     * Borrowers or users still under this partner - its own login aside, which
     * goes with it. They have to be removed or moved before it can be deleted.
     */
    public function hasBorrowersOrUsers(): bool
    {
        return $this->borrowers()->exists()
            || $this->users()
                ->where(fn ($q) => $q->where('entity_type', '!=', self::class)
                    ->orWhereNull('entity_type')
                    ->orWhere('entity_id', '!=', $this->id))
                ->exists();
    }

    /**
     * Delete partner and all related data ONLY if no paid invoices exist.
     */
    public function safeDeleteWithRelations(): bool
    {
        if (!$this->isDeletable()) {
            throw new \Exception($this->notDeletableReason());
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $isAlreadyCascading = \App\Models\User\User::$isCascading;
            \App\Models\User\User::$isCascading = true;

            // 1. Delete Borrowers and their related data recursively
            // Queried, not lazy-loaded: in a bulk delete a lazy load is refused
            // outside production, and the delete used to fail.
            $this->borrowers()->with('user')->get()->each(function ($borrower) {
                $borrower->safeDeleteWithRelations();
            });

            // 2. Delete Users directly linked to partner_id recursively 
            // (Only if not already cascading FROM a user)
            if (!$isAlreadyCascading) {
                // Check if we have a primary user for this entity
                if ($this->loadMissing('partnerUser')->partnerUser) {
                    $this->partnerUser->safeDeleteWithRelations();
                }

                // Delete other users linked to this partner
                $this->users()->get()->each(function ($user) {
                    $user->safeDeleteWithRelations();
                });
            }

            // 3. Delete Subscriptions (the phases table was dropped 2026-08-31)
            $this->subscriptions()->delete();

            // 4. Delete Invoices (Unpaid only - double checked for safety)
            $this->invoices()
                ->whereNotIn('status', \App\Models\Borrower\Borrower::PAID_INVOICE_STATUSES)
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

    public function partnerPaymentProviders()
    {
        return $this->hasMany(\App\Models\PaymentMethod\PartnerPaymentProvider::class, 'partner_id');
    }

    public function paymentProviders()
    {
        return $this->belongsToMany(\App\Models\PaymentMethod\PaymentProvider::class, 'partner_payment_providers', 'partner_id', 'payment_provider_id')
            ->withPivot(['id', 'status', 'is_default', 'configuration'])
            ->withTimestamps();
    }

    public function defaultPaymentProvider()
    {
        return $this->hasOne(\App\Models\PaymentMethod\PartnerPaymentProvider::class, 'partner_id')
            ->where('is_default', true)
            ->where('status', true);
    }
}
