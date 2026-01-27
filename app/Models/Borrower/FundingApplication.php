<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class FundingApplication extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'borrower_id',
        'status',
        'status_note',
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
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'business_checking_start_date' => 'date',
        'number_of_employees' => 'integer',
        'ownership' => 'decimal:2',
        'monthly_revenue' => 'decimal:2',
        'annual_revenue' => 'decimal:2',
        'debt_balance' => 'decimal:2',
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


    public const ON_HOLD = 'On Hold';
    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';

    public const ON_HOLD_CLASS = '';
    public const PENDING_CLASS = 'warning';
    public const APPROVED_CLASS = 'success';
    public const REJECTED_CLASS = 'danger';

    public const STATUS_CLASS = [
        self::ON_HOLD => self::ON_HOLD_CLASS,
        self::PENDING => self::PENDING_CLASS,
        self::APPROVED => self::APPROVED_CLASS,
        self::REJECTED => self::REJECTED_CLASS,
    ];

    public const STATUS = [
        self::ON_HOLD => self::ON_HOLD,
        self::PENDING => self::PENDING,
        self::APPROVED => self::APPROVED,
        self::REJECTED => self::REJECTED,
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


}
