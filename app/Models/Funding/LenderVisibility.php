<?php

namespace App\Models\Funding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Lender\Lender;
use App\Models\Funding\FundingSequencing;

class LenderVisibility extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'lender_visibilities';

    protected $fillable = [
        'lender_id',
        'funding_sequencing_id',
        'min_fico',
        'min_tib_months',
        'min_monthly_revenue',
        'min_monthly_deposits',
        'min_average_daily_balance',
        'max_nsf_count',
        'max_negative_days',
        'min_dscr',
        'allowed_states',
        'restricted_states',
        'allowed_industries',
        'restricted_industries',
    ];

    protected $casts = [
        'allowed_states' => 'array',
        'restricted_states' => 'array',
        'allowed_industries' => 'array',
        'restricted_industries' => 'array',
    ];

    public function lender()
    {
        return $this->belongsTo(Lender::class);
    }

    public function fundingSequencing()
    {
        return $this->belongsTo(FundingSequencing::class);
    }

    public function getEngineStatusForBorrower($borrower)
    {
        if (!$borrower) {
            return FundingSequencing::ENGINE_STATUS_ACTIVE;
        }

        // Ensure fundingSequencing relation is loaded to prevent lazy loading errors
        if (!$this->relationLoaded('fundingSequencing')) {
            $this->load('fundingSequencing');
        }
        $fundingSequencing = $this->fundingSequencing;

        // Geography & Industry hard stops
        if (!empty($this->restricted_states) && in_array($borrower->state, $this->restricted_states)) {
            return FundingSequencing::ENGINE_STATUS_HIDDEN;
        }
        if (!empty($this->allowed_states) && !in_array($borrower->state, $this->allowed_states)) {
            return FundingSequencing::ENGINE_STATUS_HIDDEN;
        }
        if (!empty($this->restricted_industries) && in_array($borrower->business_industry_type, $this->restricted_industries)) {
            return FundingSequencing::ENGINE_STATUS_HIDDEN;
        }
        if (!empty($this->allowed_industries) && !in_array($borrower->business_industry_type, $this->allowed_industries)) {
            return FundingSequencing::ENGINE_STATUS_HIDDEN;
        }

        $failedFinancials = 0;

        if (!is_null($this->min_fico) && (is_null($borrower->fico_score) || $borrower->fico_score < $this->min_fico)) {
            $failedFinancials++;
        }

        if (!is_null($this->min_tib_months)) {
            if ($borrower->years_in_business) {
                $tibMonths = 0;
                if (stripos($borrower->years_in_business, 'month') !== false) {
                    preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                    if (isset($matches[1])) $tibMonths = (int)$matches[1];
                } elseif (stripos($borrower->years_in_business, 'year') !== false) {
                    preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                    if (isset($matches[1])) $tibMonths = (int)$matches[1] * 12;
                }
                if ($tibMonths < $this->min_tib_months) {
                    $failedFinancials++;
                }
            } else {
                $failedFinancials++;
            }
        }

        if (!is_null($this->min_monthly_revenue) && (is_null($borrower->monthly_revenue) || $borrower->monthly_revenue < $this->min_monthly_revenue)) {
            $failedFinancials++;
        }

        if (!is_null($this->min_average_daily_balance) && (is_null($borrower->avg_bank_balance) || $borrower->avg_bank_balance < $this->min_average_daily_balance)) {
            $failedFinancials++;
        }

        if (!is_null($this->max_nsf_count) && (is_null($borrower->nsf_days) || $borrower->nsf_days > $this->max_nsf_count)) {
            $failedFinancials++;
        }

        if ($failedFinancials > 0) {
            if ($fundingSequencing->manual_review_allowed || $fundingSequencing->exception_allowed) {
                return FundingSequencing::ENGINE_STATUS_REVIEW;
            }
            return FundingSequencing::ENGINE_STATUS_GREYED_OUT;
        }

        $conditional = false;
        
        if ($fundingSequencing->require_completed_application) {
            $hasApp = \App\Models\Borrower\FundingApplication::where('borrower_id', $borrower->id)->exists();
            if (!$hasApp) {
                $conditional = true;
            }
        }

        if ($fundingSequencing->require_documents_uploaded) {
            $hasDocs = \App\Models\Borrower\BorrowerDocument::where('borrower_id', $borrower->id)->exists();
            if (!$hasDocs) {
                $conditional = true;
            }
        }

        if ($conditional) {
            return FundingSequencing::ENGINE_STATUS_WARNING;
        }

        return FundingSequencing::ENGINE_STATUS_ACTIVE;
    }
}
