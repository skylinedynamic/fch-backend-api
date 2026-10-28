<?php

namespace App\Models\Borrower;

use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\FundingRequest\FundingRequest;
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
use App\Models\Concerns\Archivable;
use App\Models\Concerns\HasEncryptedSsn;
use App\Models\Concerns\HasPersonName;


class Borrower extends Model
{
    use HasPersonName;
    use HasEncryptedSsn;

    use HasFactory, SoftDeletes, Archivable;

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
        'archived_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'partner_payment_for_me' => 'boolean',
        'is_free_trail' => 'boolean',
        'free_trail_end_date' => 'date',
        'credit_profile_updated' => 'boolean',
        'stripe_customer_id' => 'array',
    ];

    /**
     * The columns the client-info form (partner / host portal) writes, which
     * its version is taken over. Business financials, credit scores and the
     * SSN have screens and processes of their own and are left out, so a save
     * there does not make an open client-info form stale.
     */
    public const CLIENT_INFO_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'phone_number', 'email',
        'country', 'country_code', 'state', 'city', 'postal_code', 'home_address', 'date_of_birth',
        'business_name', 'business_ein', 'business_phone', 'business_address', 'business_city',
        'business_country', 'business_country_code', 'business_state', 'business_postal_code',
        'business_email', 'business_website', 'business_entity_type', 'business_entity_type_note',
        'business_industry_type', 'business_industry_type_note', 'business_description',
        'duns_number', 'state_file_number', 'business_start_date', 'status', 'assign_id', 'notes',
    ];

    /**
     * Which copy of the client's details a client-info form is showing: a
     * fingerprint of the stored CLIENT_INFO_FIELDS. The form sends it back as
     * expected_version and a save from an older copy is refused, rather than
     * putting back what another user changed meanwhile (R2 BUG-079).
     */
    public function clientInfoVersion(): string
    {
        $stored = [];
        foreach (self::CLIENT_INFO_FIELDS as $field) {
            $stored[$field] = $this->getRawOriginal($field);
        }

        return sha1(json_encode($stored));
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
                'name'        => $value['name'] ?? null,
                'card_no'     => $value['card_no'] ?? null,
            ];
        }
        return ['customer_id' => $value, 'name' => null, 'card_no' => null];
    }

    /**
     * `last_4_ssn` was renamed to `ssn_encrypted` (now holds the full SSN,
     * encrypted). These accessors/mutators keep every existing `->last_4_ssn`
     * read AND write (there are ~15 of them — resources, services, change
     * requests) working unmodified: Eloquent calls these for any attribute
     * access on a model instance, real column or not.
     *
     * The one thing this can't cover is a bulk `Borrower::where(...)->update()`
     * call, which bypasses the model entirely — those call sites were updated
     * to fetch an instance first (see ProfileService::updateProfile).
     *
     * `ssn` itself (decrypt/encrypt) and hasFullSsn() come from HasEncryptedSsn.
     */

    /**
     * Legacy accessor: last 4 digits, derived from the decrypted full SSN.
     */
    public function getLast4SsnAttribute(): ?string
    {
        $ssn = $this->ssn;
        return $ssn ? substr($ssn, -4) : null;
    }

    /**
     * Legacy mutator: callers that only ever send the last 4 digits (the admin
     * borrower form, last-4 change requests, lead conversion) still work —
     * encrypted the same way, just without the leading 5 digits.
     *
     * Those callers send the last four back on every save, so the same last
     * four as the SSN on file is no change: writing it would replace a full SSN
     * with its last four digits.
     */
    public function setLast4SsnAttribute(?string $value): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits !== '' && $digits === $this->last_4_ssn) {
            return;
        }

        $this->attributes['ssn_encrypted'] = $digits === ''
            ? null
            : \Illuminate\Support\Facades\Crypt::encryptString($digits);
    }

    const STATUS_ONBOARDING = 'OnBoarding';
    const STATUS_ACTIVE = 'Active';
    const STATUS_SUSPENDED = 'Suspended';
    const STATUS_INACTIVE = 'Inactive';

    /**
     * The statuses allowed to sign in to the borrower portal.
     *
     * An allowlist rather than a list of banned statuses. OnBoarding is the
     * default for a newly created borrower - BorrowerService and the lead
     * conversion both set it - so it has to be here; anything added to
     * getStatuses() later is denied until it is added here deliberately.
     */
    const LOGIN_ALLOWED_STATUSES = [
        self::STATUS_ONBOARDING,
        self::STATUS_ACTIVE,
    ];


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
     * The borrower's full name, middle name included when they have one.
     *
     * borrowers has no full_name column, so this accessor is safe to keep under
     * that name - it is what the rest of the system already reads.
     */
    public function getFullNameAttribute(): string
    {
        return $this->person_name;
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

    public function commissions()
    {
        return $this->hasMany(\App\Models\Commission\Commission::class, 'borrower_id');
    }

    public function fundingRequests()
    {
        return $this->hasMany(FundingRequest::class, 'borrower_id');
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
     * Per-instance answers for the funding engine's borrower checks. The engine
     * asks the same question once per lender per sequence, so without this one
     * page load ran the same EXISTS query thousands of times.
     */
    private ?bool $hasAnyFundingApplicationCache = null;
    private ?bool $hasAnyDocumentCache = null;

    public function hasAnyFundingApplication(): bool
    {
        return $this->hasAnyFundingApplicationCache ??= FundingApplication::where('borrower_id', $this->id)->exists();
    }

    public function hasAnyDocument(): bool
    {
        return $this->hasAnyDocumentCache ??= BorrowerDocument::where('borrower_id', $this->id)->exists();
    }

    /** Invoice statuses that mean money was received - a client with one is kept. */
    public const PAID_INVOICE_STATUSES = [Invoice::STATUS_PAID, Invoice::STATUS_SUCCESS];

    /** Why a client cannot be deleted - see notDeletableReasonCode(). */
    public const DELETE_BLOCKED_PAID_INVOICE = 'paid_invoice';
    public const DELETE_BLOCKED_APPLICATIONS = 'applications';

    /**
     * A client can be deleted only while none of their invoices is paid and,
     * paid or free, they have no pre-qualification and no funding application.
     */
    public function isDeletable(): bool
    {
        return $this->notDeletableReasonCode() === null;
    }

    /**
     * What keeps this client, or null when nothing does. A paid invoice is
     * reported first: the payment record is the stronger reason to keep them.
     */
    public function notDeletableReasonCode(): ?string
    {
        if ($this->invoices()->whereIn('status', self::PAID_INVOICE_STATUSES)->exists()) {
            return self::DELETE_BLOCKED_PAID_INVOICE;
        }

        // An embed-form conversion that created a funding application counts
        // too, even if that application row is no longer there.
        if ($this->preQualificationRequests()->exists()
            || $this->fundingApplications()->exists()
            || \App\Models\EmbedForm\EmbedFormSubmission::where('converted_borrower_id', $this->id)
                ->whereNotNull('converted_funding_application_id')
                ->exists()) {
            return self::DELETE_BLOCKED_APPLICATIONS;
        }

        return null;
    }

    /** Why this client cannot be deleted, and what to do instead. */
    public function notDeletableReason(): string
    {
        return $this->notDeletableReasonCode() === self::DELETE_BLOCKED_APPLICATIONS
            ? __('borrower.delete_blocked_applications')
            : __('borrower.delete_blocked_paid_invoice');
    }

    /**
     * Delete borrower and all related data ONLY if no paid invoices exist.
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

            // 1. Delete associated User recursively (only if not already cascading)
            // Loaded explicitly: in a bulk delete a lazy load is refused outside
            // production, and the delete used to fail.
            if (!$isAlreadyCascading && $this->loadMissing('user')->user) {
                // Use safeDelete to clean up user's own relations if any
                $this->user->safeDeleteWithRelations();
            }

            // 2. Delete Documents
            BorrowerDocument::where('borrower_id', $this->id)->delete();

            // 3. Delete Subscriptions (the phases table was dropped 2026-08-31)
            $this->subscriptions()->delete();

            // 4. Delete Invoices (Unpaid only - double checked for safety)
            $this->invoices()
                ->whereNotIn('status', self::PAID_INVOICE_STATUSES)
                ->delete();

            // 5. Delete other relations
            $this->fundingApplications()->delete();
            $this->preQualificationRequests()->delete();
            $this->verificationRequests()->delete();

            // Commissions not paid out go with the deal they were on; left
            // behind, they counted in the Commissions totals against a funding
            // application nobody could see any more (BUG-107). One already
            // paid out stays, as the record of that payout.
            $this->commissions()
                ->where('payment_status', '!=', \App\Models\Commission\Commission::PAYMENT_STATUS_PAID)
                ->delete();

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

    /**
     * Whether the borrower may see their credit score: their credit profile is
     * verified and the iSoftPull report has been uploaded for it (their
     * BorrowerCreditProfile). Submitting the credit profile form sets
     * `credit_profile_updated` by itself, so the flag alone is not enough.
     */
    public function hasVerifiedCreditScore(): bool
    {
        return (bool) $this->credit_profile_updated && $this->creditProfile !== null;
    }

    /**
     * Verifying every required Tier 0 task moves `current_tier` on (see
     * TaskVerificationService), so Tier 0 is done once the borrower has left it.
     */
    public function hasCompletedTier0(): bool
    {
        if (!$this->current_tier) {
            return false;
        }

        $tier0 = \App\Models\CreditBuilder\Tier::foundation();

        return $tier0 !== null && (int) $this->current_tier !== (int) $tier0->id;
    }

    /** Credit Card Stacking opens once the credit profile is verified and Tier 0 is done. */
    public function hasUnlockedCreditCardStacking(): bool
    {
        return $this->hasVerifiedCreditScore() && $this->hasCompletedTier0();
    }

    public function bankAccounts()
    {
        return $this->hasMany(\App\Models\Borrower\BorrowerBankAccount::class, 'borrower_id');
    }

    public function creditProfileRequests()
    {
        return $this->hasOne(\App\Models\Borrower\CreditProfileRequest::class, 'borrower_id');
    }

    /**
     * The borrowers a user may reach through the partner portal: the host's
     * team sees every borrower, anyone else only their own partner's - by
     * partner, not user_type, so a partner's staff are held to it too. A
     * borrower reaches only their own record, and a user with no partner none.
     */
    public function scopeVisibleTo($query, ?\App\Models\User\User $user)
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->entity_type === self::class) {
            return $query->where($this->qualifyColumn('id'), $user->entity_id);
        }

        if (!$user->partner_id) {
            return $query->whereRaw('1 = 0');
        }

        if ((bool) $user->partner?->is_host) {
            return $query;
        }

        return $query->where($this->qualifyColumn('partner_id'), $user->partner_id);
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('borrowers.first_name', 'LIKE', "%{$search}%")
                ->orWhere('borrowers.middle_name', 'LIKE', "%{$search}%")
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
                // The whole name as displayed, so "John Quincy Smith" is found by
                // searching any run of it. CONCAT_WS skips a null middle name
                // instead of collapsing the whole expression to NULL, which is what
                // plain CONCAT would do.
                ->orWhereRaw(
                    "CONCAT_WS(' ', borrowers.first_name, borrowers.middle_name, borrowers.last_name) LIKE ?",
                    ["%{$search}%"]
                );
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

