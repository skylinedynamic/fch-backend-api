<?php

namespace App\Models\Funding;

use App\Support\Locations;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class LenderVisibility extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'lender_visibilities';

    protected $fillable = [
        'lender_id',
        'lender_product_category_id',
        'funding_sequencing_id',
        'min_fico',
        'min_business_credit_score',
        'min_tib_months',
        'min_monthly_revenue',
        'min_avg_deposits',
        'min_deposit_count',
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

    public function productCategory()
    {
        return $this->belongsTo(LenderProductCategory::class, 'lender_product_category_id');
    }

    public function fundingSequencing()
    {
        return $this->belongsTo(FundingSequencing::class);
    }

    public function scopeSearch($query, $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->whereHas('lender', function ($lq) use ($term) {
                $lq->where('name', 'like', '%' . $term . '%');
            })
            ->orWhereHas('productCategory', function ($pq) use ($term) {
                $pq->where('name', 'like', '%' . $term . '%');
            });

            if (strtolower(trim($term)) === 'general') {
                $q->orWhereNull('lender_product_category_id');
            }

            $q->orWhere('min_fico', 'like', '%' . $term . '%')
            ->orWhere('min_business_credit_score', 'like', '%' . $term . '%')
            ->orWhere('min_tib_months', 'like', '%' . $term . '%')
            ->orWhere('min_monthly_revenue', 'like', '%' . $term . '%')
            ->orWhere('min_monthly_deposits', 'like', '%' . $term . '%')
            ->orWhere('min_deposit_count', 'like', '%' . $term . '%')
            ->orWhere('min_average_daily_balance', 'like', '%' . $term . '%')
            ->orWhere('max_nsf_count', 'like', '%' . $term . '%')
            ->orWhere('max_negative_days', 'like', '%' . $term . '%')
            ->orWhere('min_dscr', 'like', '%' . $term . '%')
            ->orWhere('allowed_states', 'like', '%' . $term . '%')
            ->orWhere('restricted_states', 'like', '%' . $term . '%')
            ->orWhere('allowed_industries', 'like', '%' . $term . '%')
            ->orWhere('restricted_industries', 'like', '%' . $term . '%');
        });
    }

    /**
     * A state name reduced to its code, for matching lender restrictions.
     *
     * An unrecognised value is upper-cased and returned as-is rather than
     * dropped, so a restriction written against a place this application does
     * not model still has something to compare against.
     */
    private function getStateCode($stateName)
    {
        if (!$stateName) {
            return null;
        }

        return Locations::stateCode($stateName) ?? strtoupper(trim($stateName));
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

        $borrowerStateCode = $this->getStateCode($borrower->state);

        $restrictedStates = is_array($this->restricted_states) ? array_filter($this->restricted_states) : [];
        if (!empty($restrictedStates)) {
            $restrictedStates = array_map('strtoupper', $restrictedStates);
            if (in_array($borrowerStateCode, $restrictedStates) || in_array(strtoupper(trim((string)$borrower->state)), $restrictedStates)) {
                return FundingSequencing::ENGINE_STATUS_HIDDEN;
            }
        }

        $allowedStates = is_array($this->allowed_states) ? array_filter($this->allowed_states) : [];
        if (!empty($allowedStates)) {
            $allowedStates = array_map('strtoupper', $allowedStates);
            if (!in_array($borrowerStateCode, $allowedStates) && !in_array(strtoupper(trim((string)$borrower->state)), $allowedStates)) {
                return FundingSequencing::ENGINE_STATUS_HIDDEN;
            }
        }

        $restrictedIndustries = is_array($this->restricted_industries) ? array_filter($this->restricted_industries) : [];
        if (!empty($restrictedIndustries) && in_array($borrower->business_industry_type, $restrictedIndustries)) {
            return FundingSequencing::ENGINE_STATUS_HIDDEN;
        }

        $allowedIndustries = is_array($this->allowed_industries) ? array_filter($this->allowed_industries) : [];
        if (!empty($allowedIndustries) && !in_array($borrower->business_industry_type, $allowedIndustries)) {
            return FundingSequencing::ENGINE_STATUS_HIDDEN;
        }

        $failedFinancials = 0;

        if (!is_null($this->min_fico) && (is_null($borrower->fico_score) || $borrower->fico_score < $this->min_fico)) {
            $failedFinancials++;
        }

        if (!is_null($this->min_tib_months)) {
            $tibMonths = 0;
            if ($borrower->business_start_date) {
                try {
                    $tibMonths = Carbon::parse($borrower->business_start_date)->diffInMonths(Carbon::now());
                } catch (\Exception $e) {
                    $tibMonths = 0;
                }
            } elseif ($borrower->years_in_business) {
                if (stripos($borrower->years_in_business, 'month') !== false) {
                    preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                    if (isset($matches[1])) $tibMonths = (int)$matches[1];
                } elseif (stripos($borrower->years_in_business, 'year') !== false) {
                    preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                    if (isset($matches[1])) $tibMonths = (int)$matches[1] * 12;
                }
            } else {
                $tibMonths = 0;
            }

            if ($tibMonths < $this->min_tib_months) {
                $failedFinancials++;
            }
        }

        if (!is_null($this->min_monthly_revenue) && (is_null($borrower->monthly_revenue) || $borrower->monthly_revenue < $this->min_monthly_revenue)) {
            $failedFinancials++;
        }

        if (!is_null($this->min_average_daily_balance) && (is_null($borrower->avg_bank_balance) || $borrower->avg_bank_balance < $this->min_average_daily_balance)) {
            $failedFinancials++;
        }

        if (!is_null($this->min_avg_deposits) && (is_null($borrower->average_monthly_deposits) || $borrower->average_monthly_deposits < $this->min_avg_deposits)) {
            $failedFinancials++;
        }



        if (!is_null($this->max_nsf_count) && !is_null($borrower->nsf_count) && $borrower->nsf_count > $this->max_nsf_count) {
            $failedFinancials++;
        }

        if (!is_null($this->max_negative_days) && !is_null($borrower->negative_days) && $borrower->negative_days > $this->max_negative_days) {
            $failedFinancials++;
        }

        if ($failedFinancials > 0) {
            if ($fundingSequencing->manual_review_allowed || $fundingSequencing->exception_allowed) {
                return FundingSequencing::ENGINE_STATUS_REVIEW;
            }
            return FundingSequencing::ENGINE_STATUS_GREYED_OUT;
        }

        $conditional = false;
        
        // Borrower-only facts: memoized on the Borrower instance, since this runs
        // once per lender for every sequence on the funding page.
        if ($fundingSequencing->require_completed_application) {
            $hasApp = $borrower instanceof Borrower
                ? $borrower->hasAnyFundingApplication()
                : FundingApplication::where('borrower_id', $borrower->id)->exists();
            if (!$hasApp) {
                $conditional = true;
            }
        }

        if ($fundingSequencing->require_documents_uploaded) {
            $hasDocs = $borrower instanceof Borrower
                ? $borrower->hasAnyDocument()
                : BorrowerDocument::where('borrower_id', $borrower->id)->exists();
            if (!$hasDocs) {
                $conditional = true;
            }
        }

        if ($conditional) {
            return FundingSequencing::ENGINE_STATUS_WARNING;
        }

        return FundingSequencing::ENGINE_STATUS_ACTIVE;
    }

    /**
     * Return reasons why the borrower is hidden.
     */
    public function getHiddenReasonsForBorrower($borrower): array
    {
        $reasons = [];
        $borrowerStateCode = $this->getStateCode($borrower->state);
        
        $restrictedStates = is_array($this->restricted_states) ? array_filter($this->restricted_states) : [];
        if (!empty($restrictedStates)) {
            $restrictedStates = array_map('strtoupper', $restrictedStates);
            if (in_array($borrowerStateCode, $restrictedStates) || in_array(strtoupper(trim((string)$borrower->state)), $restrictedStates)) {
                $reasons[] = [
                    'label' => 'Restricted State',
                    'status' => 'Missing',
                    'borrower_value' => $borrower->state,
                    'required_value' => 'Not Restricted'
                ];
            }
        }

        $allowedStates = is_array($this->allowed_states) ? array_filter($this->allowed_states) : [];
        if (!empty($allowedStates)) {
            $allowedStates = array_map('strtoupper', $allowedStates);
            if (!in_array($borrowerStateCode, $allowedStates) && !in_array(strtoupper(trim((string)$borrower->state)), $allowedStates)) {
                $reasons[] = [
                    'label' => 'State Eligibility',
                    'status' => 'Missing',
                    'borrower_value' => $borrower->state,
                    'required_value' => implode(', ', $this->allowed_states)
                ];
            }
        }

        $restrictedIndustries = is_array($this->restricted_industries) ? array_filter($this->restricted_industries) : [];
        if (!empty($restrictedIndustries) && in_array($borrower->business_industry_type, $restrictedIndustries)) {
            $reasons[] = [
                'label' => 'Restricted Industry',
                'status' => 'Missing',
                'borrower_value' => $borrower->business_industry_type,
                'required_value' => 'Not Restricted'
            ];
        }

        $allowedIndustries = is_array($this->allowed_industries) ? array_filter($this->allowed_industries) : [];
        if (!empty($allowedIndustries) && !in_array($borrower->business_industry_type, $allowedIndustries)) {
            $reasons[] = [
                'label' => 'Industry Eligibility',
                'status' => 'Missing',
                'borrower_value' => $borrower->business_industry_type,
                'required_value' => implode(', ', $this->allowed_industries)
            ];
        }

        return $reasons;
    }
}
