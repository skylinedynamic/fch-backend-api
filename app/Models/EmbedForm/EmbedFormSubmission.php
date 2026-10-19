<?php

namespace App\Models\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\HasPersonName;

class EmbedFormSubmission extends Model
{
    use HasPersonName;

    protected $table = 'embedded_form_submissions';

    protected $guarded = [];

    protected $casts = [
        // Ownership
        'date_of_birth' => 'date',
        'ownership' => 'decimal:2',
        'has_co_borrower' => 'boolean',

        // Business
        'number_of_employees' => 'integer',

        // Financial
        'monthly_revenue' => 'decimal:2',
        'annual_revenue' => 'decimal:2',
        'business_checking_start_date' => 'date',
        'has_business_debt' => 'boolean',
        'debt_balance' => 'decimal:2',

        // Funding request
        'requested_amount' => 'decimal:2',
        'additional_funding_types' => 'array',

        // Disclosures
        'credit_pull_authorization' => 'boolean',
        'terms_conditions_acceptance' => 'boolean',

        // Federal compliance
        'filed_for_bankruptcy' => 'boolean',
        'any_pending_lawsuits' => 'boolean',
        'has_federal_loan' => 'boolean',
        'has_federal_debt' => 'boolean',
        'has_criminal_offense' => 'boolean',
        'has_parole_or_probation' => 'boolean',
        'suspended_or_debarred' => 'boolean',
        'any_outstanding_judgments_business' => 'boolean',
        'any_outstanding_judgments_owner' => 'boolean',

        // Estimated credit score, self-reported by the applicant on a 300-850
        // scale. Never present this as a bureau-verified figure.
        'credit_score' => 'integer',

        // Attestation
        'attestation_authorization' => 'boolean',
        'esign_consent' => 'boolean',
        'terms_consent' => 'boolean',
        'credit_consent' => 'boolean',
        'legal_binding_consent' => 'boolean',
        'signature_data' => 'array',

        // Deprecated consumer-loan columns, still cast so historic submissions
        // taken before the funding application rewrite still read correctly.
        'birthday' => 'date',
        'desired_funding_amount' => 'decimal:2',
        'monthly_housing_payment' => 'decimal:2',
        'annual_income' => 'decimal:2',

        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = ['ssn_encrypted'];

    /**
     * Columns the public form no longer collects.
     *
     * Kept on the table because pre-rewrite submissions hold their only copy of
     * this data. A submission with any of these set is rendered with the legacy
     * section in the partner portal.
     */
    public const DEPRECATED_CONSUMER_FIELDS = [
        'birthday',
        'desired_funding_amount',
        'home_address',
        'postal_code',
        'monthly_housing_payment',
        'housing_status',
        'employer_name',
        'employer_phone',
        'employer_address',
        'occupation',
        'annual_income',
    ];

    const STATUS_NEW = 'new';
    const STATUS_REVIEWING = 'reviewing';
    const STATUS_CONVERTED = 'converted';
    const STATUS_REJECTED = 'rejected';

    public static function getStatuses(): array
    {
        return [
            self::STATUS_NEW,
            self::STATUS_REVIEWING,
            self::STATUS_CONVERTED,
            self::STATUS_REJECTED,
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function formConfig(): BelongsTo
    {
        return $this->belongsTo(EmbedFormConfig::class, 'form_config_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmbedFormAttachment::class, 'submission_id');
    }

    public function coBorrowers(): HasMany
    {
        return $this->hasMany(EmbedFormCoBorrower::class, 'submission_id');
    }

    /**
     * The partner user picked as sales rep on the public form.
     */
    public function salesRepUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_user_id');
    }

    public function convertedBorrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'converted_borrower_id');
    }

    public function convertedFundingApplication(): BelongsTo
    {
        return $this->belongsTo(FundingApplication::class, 'converted_funding_application_id');
    }

    /**
     * The owner's name, from the ownership step.
     *
     * Deliberately NOT called full_name: that is now a real column holding the
     * business contact's name from the business information step, and an
     * accessor of the same name would shadow it everywhere.
     */
    public function getApplicantNameAttribute(): string
    {
        return $this->person_name;
    }

    /**
     * True when this row predates the funding application rewrite and still
     * carries consumer-loan answers that have nowhere else to be shown.
     */
    public function hasLegacyConsumerData(): bool
    {
        foreach (self::DEPRECATED_CONSUMER_FIELDS as $field) {
            if (!empty($this->getAttribute($field))) {
                return true;
            }
        }

        return false;
    }
}
