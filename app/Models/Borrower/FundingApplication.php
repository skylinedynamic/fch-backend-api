<?php

namespace App\Models\Borrower;

use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class FundingApplication extends Model
{
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
        'first_name',
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
        'currency',
        'currency_symbol',
        'conversion_rate',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'funded_date' => 'date',
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
    public const PENDING = 'Pending';
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
    public const ON_HOLD = 'On Hold';

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

    public const STATUS_CLASS = [
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
        self::ON_HOLD => self::ON_HOLD_CLASS,
    ];

    public const STATUS = [
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
        self::ON_HOLD => self::ON_HOLD,
    ];

    public function getStatusClassAttribute(): string
    {
        return self::STATUS_CLASS[$this->status] ?? '';
    }

    /**
     * Get the borrower that owns the funding application.
     */
    public function borrower()
    {
        return $this->belongsTo(\App\Models\Borrower\Borrower::class);
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

    public function scopeSearch($query, $term)
    {
        $like = "%{$term}%";

        return $query->where(function (Builder $q) use ($like) {
            $q->where('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('full_name', 'like', $like)
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
              ->orWhere('business_zip_code', 'like', $like);
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
}
