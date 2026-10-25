<?php

namespace App\Models\Borrower;

use App\Models\Borrower\DownloadHistory;
use App\Models\Commission\Commission;
use App\Models\Funding\FundingSequencing;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasPersonName;

class FundingApplication extends Model
{
    use HasPersonName;

    use SoftDeletes;
    
    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::created(function ($application) {
            $application->application_number = 'FUND-' . str_pad($application->id, 5, '0', STR_PAD_LEFT) . $application->id;
            $application->saveQuietly();
        });
    }

    protected $fillable = [
        'application_number',
        'borrower_id',
        'partner_id',
        'is_partner_prepared',
        'status',
        'status_note',
        'admin_commission',
        'admin_commission_amount',
        'partner_commission',
        'partner_commission_amount',
        'total_funded_amount',
        'funded_note',
        'full_name',
        'business_name',
        'business_email',
        'business_phone_no',
        'business_country',
        'business_country_code',
        'business_address',
        'business_city',
        'business_state',
        'business_postal_code',
        'industry_type',
        'entity_type',
        'years_in_business',
        'number_of_employees',
        'additional_funding_types',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'ssn',
        'phone_number',
        'citizenship_status',
        'street_address',
        'country',
        'city',
        'state',
        'zip_code',
        'ownership',
        'has_co_borrower',
        'monthly_revenue',
        'annual_revenue',
        'business_bank_name',
        'business_checking_start_date',
        'self_reported_personal_credit_score',
        'self_reported_business_credit_score',
        'has_business_debt',
        'debt_balance',
        'requested_amount',
        'purpose_of_funds',
        'approved_funding',
        'purpose_note',
        'preferred_funding_type',
        'timeframe_needed',
        'collateral_available',
        'credit_pull_authorization',
        'terms_conditions_acceptance',
        'filed_for_bankruptcy',
        'any_pending_lawsuits',
        'has_federal_loan',
        'has_federal_debt',
        'has_criminal_offense',
        'has_parole_or_probation',
        'suspended_or_debarred',
        'any_outstanding_judgments_business',
        'any_outstanding_judgments_owner',
        'federal_compliance_disclosures_explanation',
        'attestation_authorization',
        'signature_data',
        'previous_signature_data',
        'requires_resign',
        'currency',
        'currency_symbol',
        'conversion_rate',
        'funding_sequence_id',
        'funding_request_id',
        'is_business_info_saved',
        'is_ownership_info_saved',
        'is_financial_info_saved',
        'is_funding_request_saved',
        'is_disclosures_saved',
        'is_federal_compliance_saved',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'funded_date' => 'date',
        'signature_data' => 'array',
        'previous_signature_data' => 'array',
        'additional_funding_types' => 'array',
        'requires_resign' => 'boolean',

        'is_business_info_saved' => 'boolean',
        'is_ownership_info_saved' => 'boolean',
        'is_financial_info_saved' => 'boolean',
        'is_funding_request_saved' => 'boolean',
        'is_disclosures_saved' => 'boolean',
        'is_federal_compliance_saved' => 'boolean',

        'business_checking_start_date' => 'date',
        'number_of_employees' => 'integer',
        'ownership' => 'decimal:2',
        'monthly_revenue' => 'decimal:2',
        'annual_revenue' => 'decimal:2',
        'debt_balance' => 'decimal:2',
        'admin_commission' => 'decimal:2',
        'admin_commission_amount' => 'decimal:2',
        'partner_commission' => 'decimal:2',
        'partner_commission_amount' => 'decimal:2',
        'total_funded_amount' => 'decimal:2',
        'requested_amount' => 'decimal:2',
        'has_co_borrower' => 'boolean',
        'has_business_debt' => 'boolean',
        'credit_pull_authorization' => 'boolean',
        'terms_conditions_acceptance' => 'boolean',
        'filed_for_bankruptcy' => 'boolean',
        'any_pending_lawsuits' => 'boolean',
        'has_federal_loan' => 'boolean',
        'has_federal_debt' => 'boolean',
        'has_criminal_offense' => 'boolean',
        'has_parole_or_probation' => 'boolean',
        'suspended_or_debarred' => 'boolean',
        'any_outstanding_judgments_business' => 'boolean',
        'any_outstanding_judgments_owner' => 'boolean',
        'attestation_authorization' => 'boolean',
    ];


    // Status Constants
    public const ON_HOLD = 'On Hold';
    public const DRAFT = 'Draft';
    public const PENDING = 'Submitted – Pending Review';
    public const SUBMITTED = 'Submitted (Received)';
    public const UNDERWRITING_REVIEW = 'Underwriting Review';
    public const MISSING_DOCUMENTS = 'Missing Documents (Action Required)';
    public const IN_VERIFICATION = 'In Verification';
    public const APPROVED_CONDITIONAL = 'Approved (Conditional)';
    public const APPROVED_FINAL = 'Approved (Final)';
    public const MATCHED_TO_LENDER = 'Matched to Lender';
    public const SUBMITTED_TO_LENDER = 'Submitted to Lender';
    public const LENDER_REVIEWING = 'Lender Reviewing';
    public const OFFER_ISSUED = 'Offer Issued';
    public const OFFER_ACCEPTED = 'Offer Accepted';
    public const CONTRACTING_DOCS_OUT = 'Contracting / Docs Out';
    public const CLEARED_TO_FUND = 'Cleared to Fund';
    public const FUNDED = 'Funded';
    public const CLOSED_COMPLETED = 'Closed (Completed)';
    public const DENIED = 'Denied';

    // The status order itself is config: `funding_application.statuses.order`.
    // This is only the last order number borrower and partner may still edit
    // at, if config omits `max_editable_key`.
    public const MAX_EDITABLE_STATUS_KEY = 9;
    

    // Status Class Constants
    public const PENDING_CLASS = 'warning';
    public const SUBMITTED_CLASS = 'info';
    public const UNDERWRITING_REVIEW_CLASS = 'primary';
    public const MISSING_DOCUMENTS_CLASS = 'warning';
    public const IN_VERIFICATION_CLASS = 'primary';
    public const APPROVED_CONDITIONAL_CLASS = 'success';
    public const APPROVED_FINAL_CLASS = 'success';
    public const MATCHED_TO_LENDER_CLASS = 'info';
    public const SUBMITTED_TO_LENDER_CLASS = 'info';
    public const LENDER_REVIEWING_CLASS = 'primary';
    public const OFFER_ISSUED_CLASS = 'success';
    public const OFFER_ACCEPTED_CLASS = 'success';
    public const CONTRACTING_DOCS_OUT_CLASS = 'primary';
    public const CLEARED_TO_FUND_CLASS = 'success';
    public const FUNDED_CLASS = 'success';
    public const CLOSED_COMPLETED_CLASS = 'secondary';
    public const ON_HOLD_CLASS = 'warning';
    public const DRAFT_CLASS = 'secondary';
    public const DENIED_CLASS = 'danger';

    public const STATUS_CLASS = [
        self::ON_HOLD => self::ON_HOLD_CLASS,
        self::DRAFT => self::DRAFT_CLASS,
        self::PENDING => self::PENDING_CLASS,
        self::SUBMITTED => self::SUBMITTED_CLASS,
        self::UNDERWRITING_REVIEW => self::UNDERWRITING_REVIEW_CLASS,
        self::MISSING_DOCUMENTS => self::MISSING_DOCUMENTS_CLASS,
        self::IN_VERIFICATION => self::IN_VERIFICATION_CLASS,
        self::APPROVED_CONDITIONAL => self::APPROVED_CONDITIONAL_CLASS,
        self::APPROVED_FINAL => self::APPROVED_FINAL_CLASS,
        self::MATCHED_TO_LENDER => self::MATCHED_TO_LENDER_CLASS,
        self::SUBMITTED_TO_LENDER => self::SUBMITTED_TO_LENDER_CLASS,
        self::LENDER_REVIEWING => self::LENDER_REVIEWING_CLASS,
        self::OFFER_ISSUED => self::OFFER_ISSUED_CLASS,
        self::OFFER_ACCEPTED => self::OFFER_ACCEPTED_CLASS,
        self::CONTRACTING_DOCS_OUT => self::CONTRACTING_DOCS_OUT_CLASS,
        self::CLEARED_TO_FUND => self::CLEARED_TO_FUND_CLASS,
        self::FUNDED => self::FUNDED_CLASS,
        self::CLOSED_COMPLETED => self::CLOSED_COMPLETED_CLASS,
        self::DENIED => self::DENIED_CLASS,
    ];

    public const STATUS = [
        self::ON_HOLD => self::ON_HOLD,
        self::DRAFT => self::DRAFT,
        self::PENDING => self::PENDING,
        self::SUBMITTED => self::SUBMITTED,
        self::UNDERWRITING_REVIEW => self::UNDERWRITING_REVIEW,
        self::MISSING_DOCUMENTS => self::MISSING_DOCUMENTS,
        self::IN_VERIFICATION => self::IN_VERIFICATION,
        self::APPROVED_CONDITIONAL => self::APPROVED_CONDITIONAL,
        self::APPROVED_FINAL => self::APPROVED_FINAL,
        self::MATCHED_TO_LENDER => self::MATCHED_TO_LENDER,
        self::SUBMITTED_TO_LENDER => self::SUBMITTED_TO_LENDER,
        self::LENDER_REVIEWING => self::LENDER_REVIEWING,
        self::OFFER_ISSUED => self::OFFER_ISSUED,
        self::OFFER_ACCEPTED => self::OFFER_ACCEPTED,
        self::CONTRACTING_DOCS_OUT => self::CONTRACTING_DOCS_OUT,
        self::CLEARED_TO_FUND => self::CLEARED_TO_FUND,
        self::FUNDED => self::FUNDED,
        self::CLOSED_COMPLETED => self::CLOSED_COMPLETED,
        self::DENIED => self::DENIED,
    ];

    public const PURPOSE_OF_FUNDING_OPTIONS = [
        'Working Capital' => 'Working Capital',
        'Equipment'       => 'Equipment',
        'Inventory'       => 'Inventory',
        'Marketing'       => 'Marketing',
        'Expansion'       => 'Expansion',
        'Other'           => 'Other',
    ];

    public function getStatusClassAttribute(): string
    {
        return self::STATUS_CLASS[$this->status] ?? '';
    }

    /*
    |--------------------------------------------------------------------------
    | Status Lifecycle
    |--------------------------------------------------------------------------
    |
    | The order lives in config/funding_application.php under `statuses.order`,
    | and every bucket below is worked out from it. The constants above stay the
    | source of the strings; config decides where each one sits and therefore
    | what it lets a borrower or partner do.
    |
    | A status with no order number is read-only, so a missing or truncated
    | config section fails closed instead of silently unlocking a funded
    | application.
    |
    */

    /**
     * Every status with its order number, earliest first.
     *
     * @return array<string, int>
     */
    public static function statusKeys(): array
    {
        $keys = array_map('intval', config('funding_application.statuses.order', []));
        asort($keys);

        return $keys;
    }

    /**
     * A status's lifecycle key, or null for one nobody has numbered.
     */
    public static function statusKey(?string $status): ?int
    {
        return self::statusKeys()[self::canonicalStatus($status)] ?? null;
    }

    /**
     * Sort by where each application sits in the lifecycle (key 1-19) rather
     * than alphabetically by the status name. A status with no key sorts last.
     */
    public function scopeOrderByStatusKey($query, string $direction = 'asc')
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        if (self::statusKeys() === []) {
            return $query->orderBy($query->qualifyColumn('status'), $direction);
        }

        [$case, $bindings] = self::statusKeyCase($query->qualifyColumn('status'));

        return $query->orderByRaw("{$case} {$direction}", $bindings);
    }

    /**
     * SQL turning a status column into its lifecycle key (unknown statuses
     * last), with its bindings - for ordering by status from another table,
     * e.g. a funding request by its application's status.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public static function statusKeyCase(string $column): array
    {
        $keys = self::statusKeys();
        if ($keys === []) {
            return ['0', []];
        }

        $cases = '';
        $bindings = [];

        foreach ($keys as $status => $key) {
            $cases .= ' WHEN ? THEN ' . $key;
            $bindings[] = $status;
        }

        foreach (config('funding_application.statuses.legacy_aliases', []) as $legacy => $status) {
            if (isset($keys[$status])) {
                $cases .= ' WHEN ? THEN ' . $keys[$status];
                $bindings[] = $legacy;
            }
        }

        $unknown = max($keys) + 1;

        return ["CASE {$column}{$cases} ELSE {$unknown} END", $bindings];
    }

    /**
     * The highest key borrower and partner may still edit at.
     */
    public static function maxEditableStatusKey(): int
    {
        return (int) config('funding_application.statuses.max_editable_key', self::MAX_EDITABLE_STATUS_KEY);
    }

    /**
     * Every status where borrower and partner may still change the application:
     * those whose key is at or below the editable limit.
     *
     * Anything else is read-only, including a status with no key at all. Failing
     * closed is deliberate: an unknown status is more likely to be one added
     * past the point of editing than one that should quietly stay open.
     * Genuinely old values are mapped instead, through `statuses.legacy_aliases`.
     *
     * @return array<int, string>
     */
    public static function editableStatuses(): array
    {
        $limit = self::maxEditableStatusKey();

        return array_keys(array_filter(self::statusKeys(), static fn (int $key) => $key <= $limit));
    }

    /**
     * Read a status written by an older version of the application as its modern
     * equivalent, so it classifies the same way.
     */
    public static function canonicalStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return config('funding_application.statuses.legacy_aliases', [])[$status] ?? $status;
    }

    /**
     * Statuses where borrower and partner may not edit the application at all.
     *
     * @return array<int, string>
     */
    public static function lockedStatuses(): array
    {
        $limit = self::maxEditableStatusKey();

        return array_keys(array_filter(self::statusKeys(), static fn (int $key) => $key > $limit));
    }

    /**
     * Statuses where this round is settled - the application has left the
     * borrower's hands - so they may submit a new funding request.
     *
     * @return array<int, string>
     */
    public static function statusesAllowingNewRequest(): array
    {
        return self::lockedStatuses();
    }

    /**
     * Statuses that carry a signature, so a real field change invalidates it.
     *
     * @return array<int, string>
     */
    public static function resignOnEditStatuses(): array
    {
        // Editable, and past the point where nothing had been signed.
        return array_values(array_diff(self::editableStatuses(), self::preSignatureStatuses()));
    }

    /**
     * Statuses where the wizard is still being filled in and nothing is signed.
     *
     * @return array<int, string>
     */
    public static function preSignatureStatuses(): array
    {
        return config('funding_application.statuses.pre_signature', [
            self::ON_HOLD,
            self::DRAFT,
        ]);
    }

    /**
     * Where a signature-invalidating edit puts the application.
     */
    public static function resignTargetStatus(): string
    {
        return config('funding_application.statuses.resign_target', self::DRAFT);
    }

    /**
     * Fields that don't count when deciding whether a save changed anything.
     *
     * @return array<int, string>
     */
    public static function editComparisonIgnoredFields(): array
    {
        return config('funding_application.statuses.edit_comparison_ignored_fields', []);
    }

    /**
     * The file is with a lender, under contract, funded or closed - borrower and
     * partner are read-only from here on.
     */
    public function isLockedForEdit(): bool
    {
        return ! $this->isEditable();
    }

    /**
     * This round is settled: the borrower may submit a new funding request.
     */
    public function allowsNewFundingRequest(): bool
    {
        return in_array(self::canonicalStatus($this->status), self::statusesAllowingNewRequest(), true);
    }

    /**
     * A signature is on file, so a real field change costs it.
     */
    public function invalidatesSignatureOnEdit(): bool
    {
        return in_array(self::canonicalStatus($this->status), self::resignOnEditStatuses(), true);
    }

    /**
     * The application has been signed and submitted at least once, even if an
     * edit or an admin has since put it back to On Hold or Draft.
     */
    public function hasBeenSubmitted(): bool
    {
        return $this->invalidatesSignatureOnEdit()
            || (bool) $this->requires_resign
            || ! empty($this->signature_data)
            || ! empty($this->previous_signature_data);
    }

    /**
     * Borrower/partner may still change the application's data.
     */
    public function isEditable(): bool
    {
        $key = self::statusKey($this->status);

        return $key !== null && $key <= self::maxEditableStatusKey();
    }

    /**
     * Lifecycle key, for serialising alongside a raw model (`append`).
     */
    public function getStatusKeyAttribute(): ?int
    {
        return self::statusKey($this->status);
    }

    /**
     * Editability, for serialising alongside a raw model (`append`).
     */
    public function getIsEditableAttribute(): bool
    {
        return $this->isEditable();
    }

    /**
     * The signature an edit cleared, for the re-signing step to show beside the
     * field for the new one. Null unless a new signature is actually owed.
     *
     * Applications edited before the signature was being kept have nothing
     * stored, so those fall back to the submission entry in the activity log,
     * which carries the signer's name and when they signed.
     *
     * @return array{signature_name: ?string, signed_at: ?string, timezone: ?string}|null
     */
    public function previousSignature(): ?array
    {
        if (! $this->requires_resign) {
            return null;
        }

        $previous = $this->previous_signature_data;

        if (is_array($previous) && ! empty($previous['signature_name'])) {
            return [
                'signature_name' => $previous['signature_name'],
                'signature_type' => $previous['signature_type'] ?? 'typed',
                'signature_image' => $previous['signature_image'] ?? null,
                'signed_at' => $previous['signed_at'] ?? null,
                'timezone' => $previous['timezone'] ?? null,
            ];
        }

        $log = \App\Models\ActivityLog\ActivityLog::where('subject_type', self::class)
            ->where('subject_id', $this->id)
            ->whereIn('event', ['Funding Application Submitted', 'Funding Application Re-signed and Re-submitted'])
            ->latest('id')
            ->first();

        $signerName = $log?->properties['actor_name'] ?? null;

        if (! $signerName) {
            return null;
        }

        return [
            'signature_name' => $signerName,
            'signed_at' => $log->created_at?->format('Y-m-d H:i:s'),
            'timezone' => config('app.timezone'),
        ];
    }

    /**
     * Everything a portal needs to decide what to show: whether the form is
     * open, what an edit will cost, and whether the borrower may start over.
     *
     * Both portals spread this into their resources rather than each deriving
     * its own flags, so "locked" means the same thing on every screen and in
     * the server-side check that actually enforces it.
     *
     * @return array<string, mixed>
     */
    public function editingState(): array
    {
        $isLocked = $this->isLockedForEdit();

        return [
            'status_key' => self::statusKey($this->status),
            'max_editable_status_key' => self::maxEditableStatusKey(),
            'is_locked' => $isLocked,
            'is_editable' => ! $isLocked,
            'requires_resign' => (bool) $this->requires_resign,
            // True when the form is open but a change will cost the signature -
            // what the portal warns about before letting an edit through.
            'edit_requires_resign' => ! $isLocked && $this->invalidatesSignatureOnEdit(),
            // True when the form is open and the application has been submitted
            // at some point, whatever status it sits at now - On Hold and Draft
            // included. The portal asks before letting an edit through on these;
            // one that was never submitted is just being filled in, so it is not.
            'edit_needs_confirmation' => ! $isLocked && $this->hasBeenSubmitted(),
            'can_start_new_funding_request' => $this->allowsNewFundingRequest(),
            'locked_reason' => $isLocked
                ? __('application.funding_application_locked', ['status' => $this->status])
                : null,
        ];
    }

    /**
     * Get the borrower that owns the funding application.
     */
    public function borrower()
    {
        return $this->belongsTo(\App\Models\Borrower\Borrower::class);
    }

    /**
     * The applications on a user's Intake & Review book, by the rule that
     * screen's lists use: a partner's team (user_type Partner) works its own
     * partner's book, the host's team (user_type Admin) every book, and a
     * borrower only their own applications.
     */
    public function scopeReviewableBy($query, ?\App\Models\User\User $user)
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->entity_type === \App\Models\Borrower\Borrower::class) {
            return $query->where($this->qualifyColumn('borrower_id'), $user->entity_id);
        }

        if ($user->user_type !== \App\Models\User\User::ADMIN) {
            return $query->where($this->qualifyColumn('partner_id'), $user->partner_id);
        }

        return $query;
    }

    /** An application is reachable exactly as far as its borrower is - see Borrower::scopeVisibleTo(). */
    public function scopeVisibleTo($query, ?\App\Models\User\User $user)
    {
        return $query->whereHas('borrower', fn ($borrowers) => $borrowers->visibleTo($user));
    }

    /**
     * Get the co-borrowers for the funding application.
     */
    public function coborrowers()
    {
        return $this->hasMany(FundingApplicationCoBorrower::class);
    }

    /**
     * Get the funding documents for the funding application.
     */
    public function fundingDocuments()
    {
        return $this->hasMany(FundingApplicationDocument::class);
    }

    /**
     * The Funding Applications list search: the columns the list shows, the
     * applicant and business details on the application, and the borrower the
     * application belongs to.
     *
     * A name is matched word by word, so "Michael Chen" finds a first name of
     * "Michael" with a last name of "Chen" (and a middle name in between)
     * although no single column holds the whole phrase.
     */
    public function scopeSearch($query, $term)
    {
        $term = trim((string) $term);
        $like = "%{$term}%";
        $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Every word of the term must appear in one of the given name columns.
        $nameMatches = function ($q, array $columns) use ($words) {
            foreach ($words as $word) {
                $q->where(function ($inner) use ($columns, $word) {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'like', "%{$word}%");
                    }
                });
            }
        };

        return $query->where(function (Builder $q) use ($like, $words, $nameMatches) {
            $q->where('first_name', 'like', $like)
              ->orWhere('middle_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('full_name', 'like', $like)
              ->orWhere('application_number', 'like', $like)
              ->orWhere('industry_type', 'like', $like)
              ->orWhere('entity_type', 'like', $like)
              ->orWhere('purpose_of_funds', 'like', $like)
              ->orWhere('status', 'like', $like)
              ->orWhere('business_name', 'like', $like)
              ->orWhere('business_email', 'like', $like)
              ->orWhere('business_phone_no', 'like', $like)
              ->orWhere('city', 'like', $like)
              ->orWhere('state', 'like', $like)
              ->orWhere('zip_code', 'like', $like)
              ->orWhere('business_country', 'like', $like)
              ->orWhere('business_country_code', 'like', $like)
              ->orWhere('business_address', 'like', $like)
              ->orWhere('business_city', 'like', $like)
              ->orWhere('business_state', 'like', $like)
              ->orWhere('business_postal_code', 'like', $like);

            if ($words === []) {
                return;
            }

            // The applicant's name as entered on the application...
            $q->orWhere(fn ($name) => $nameMatches($name, ['first_name', 'middle_name', 'last_name']));

            // ...and the borrower's own record: name, email, phone, business.
            $q->orWhereHas('borrower', function ($borrower) use ($like, $nameMatches) {
                $borrower->where(function ($b) use ($like, $nameMatches) {
                    $b->where(fn ($name) => $nameMatches($name, ['first_name', 'middle_name', 'last_name']))
                      ->orWhere('email', 'like', $like)
                      ->orWhere('phone_number', 'like', $like)
                      ->orWhere('business_name', 'like', $like);
                });
            });
        });
    }


    public function commission()
    {
        return $this->hasOne(Commission::class,'funding_application_id','id');
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function fundingSequence()
    {
        return $this->belongsTo(FundingSequencing::class, 'funding_sequence_id');
    }

    public function fundingRequest()
    {
        return $this->belongsTo(FundingRequest::class, 'funding_request_id');
    }



    public function downloadHistories()
    {
        return $this->hasMany(DownloadHistory::class, 'funding_application_id')->orderBy('created_at', 'desc');
    }
}
