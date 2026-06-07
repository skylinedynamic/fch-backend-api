<?php

namespace App\Models\Funding;

use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundingSequencing extends Model
{
    use HasFactory, SoftDeletes;

    // ── Status constants ───────────────────────────────────────────────────────
    const STATUS_ACTIVE   = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    // ── Engine Status constants ────────────────────────────────────────────────
    const ENGINE_STATUS_ACTIVE     = 'Active';
    const ENGINE_STATUS_WARNING    = 'Warning';
    const ENGINE_STATUS_REVIEW     = 'Review';
    const ENGINE_STATUS_GREYED_OUT = 'Greyed Out';
    const ENGINE_STATUS_HIDDEN     = 'Hidden';

    const ENGINE_LABEL_ACTIVE      = 'Eligible Now';
    const ENGINE_LABEL_WARNING     = 'Conditional';
    const ENGINE_LABEL_REVIEW      = 'Manual Review Required';
    const ENGINE_LABEL_GREYED_OUT  = 'Better Fit Later';
    const ENGINE_LABEL_HIDDEN      = 'Not Eligible';
    const ENGINE_LABEL_LOCKED      = 'Locked';

    protected $fillable = [
        'funding_group_id',
        'funding_type_id',
        'name',
        'subtitle',
        'description',
        'amount_range',
        'terms',
        'speed_to_funds',
        'uses',
        'qualify_reasons',
        'readiness_factors',
        'missing_documents',
        'icon',
        'icon_color',
        'status',
        'sort_order',
        'action_url',
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
        'require_completed_application',
        'require_documents_uploaded',
        'manual_review_allowed',
        'exception_allowed',
    ];

    protected $casts = [
        'allowed_states' => 'array',
        'restricted_states' => 'array',
        'allowed_industries' => 'array',
        'restricted_industries' => 'array',
        'require_completed_application' => 'boolean',
        'require_documents_uploaded' => 'boolean',
        'manual_review_allowed' => 'boolean',
        'exception_allowed' => 'boolean',
        'uses' => 'array',
        'qualify_reasons' => 'array',
        'readiness_factors' => 'array',
        'missing_documents' => 'array',
    ];

    protected $appends = [
        'engine_status',
        'engine_status_label',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FundingGroup::class, 'funding_group_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(FundingType::class, 'funding_type_id');
    }

    public function scopeSearch($query, $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', '%' . $term . '%')
              ->orWhere('subtitle', 'like', '%' . $term . '%')
              ->orWhere('description', 'like', '%' . $term . '%')
              ->orWhere('amount_range', 'like', '%' . $term . '%')
              ->orWhere('terms', 'like', '%' . $term . '%')
              ->orWhere('status', 'like', '%' . $term . '%')
              ->orWhereHas('group', function ($q) use ($term) {
                  $q->where('name', 'like', '%' . $term . '%');
              })
              ->orWhereHas('type', function ($q) use ($term) {
                  $q->where('name', 'like', '%' . $term . '%');
              });
        });
    }

    /**
     * Virtual attributes to expose engine status via the model itself
     */
    public function getEngineStatusAttribute()
    {
        // 1. Inactive Sequences are Hidden
        if ($this->status !== self::STATUS_ACTIVE) {
            return self::ENGINE_STATUS_HIDDEN;
        }

        // 2. Disabled Funding Types are Hidden
        if ($this->relationLoaded('type') && $this->type && $this->type->status !== self::STATUS_ACTIVE) {
            return self::ENGINE_STATUS_HIDDEN;
        }

        // 3. Get Authenticated Borrower
        $borrower = null;
        if (auth()->check() && auth()->user() && auth()->user()->user_type === User::BORROWER && auth()->user()->borrower) {
            $borrower = auth()->user()->borrower;
        }

        // If no borrower profile, assume they just need to complete it, 
        // but default to Active (Eligible Now) to show the option, or Conditional.
        if (!$borrower) {
            return self::ENGINE_STATUS_ACTIVE;
        }

        // 4. Non-waivable hard stops (Geography & Industry) -> Hidden
        if (!empty($this->restricted_states) && in_array($borrower->state, $this->restricted_states)) {
            return self::ENGINE_STATUS_HIDDEN;
        }
        if (!empty($this->allowed_states) && !in_array($borrower->state, $this->allowed_states)) {
            return self::ENGINE_STATUS_HIDDEN;
        }
        if (!empty($this->restricted_industries) && in_array($borrower->business_industry_type, $this->restricted_industries)) {
            return self::ENGINE_STATUS_HIDDEN;
        }
        if (!empty($this->allowed_industries) && !in_array($borrower->business_industry_type, $this->allowed_industries)) {
            return self::ENGINE_STATUS_HIDDEN;
        }

        // 5. Evaluate Financial Thresholds
        $failedFinancials = 0;

        // FICO
        if (!is_null($this->min_fico)) {
            if (is_null($borrower->fico_score) || $borrower->fico_score < $this->min_fico) {
                $failedFinancials++;
            }
        }

        // Time In Business (parse "6-12 months", "1-3 years" etc)
        if (!is_null($this->min_tib_months) && $borrower->years_in_business) {
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
        } elseif (!is_null($this->min_tib_months) && is_null($borrower->years_in_business)) {
            $failedFinancials++;
        }

        // Monthly Revenue
        if (!is_null($this->min_monthly_revenue)) {
            if (is_null($borrower->monthly_revenue) || $borrower->monthly_revenue < $this->min_monthly_revenue) {
                $failedFinancials++;
            }
        }

        // Average Daily Balance
        if (!is_null($this->min_average_daily_balance)) {
            if (is_null($borrower->avg_bank_balance) || $borrower->avg_bank_balance < $this->min_average_daily_balance) {
                $failedFinancials++;
            }
        }

        // NSF Count
        if (!is_null($this->max_nsf_count)) {
            if (is_null($borrower->nsf_days) || $borrower->nsf_days > $this->max_nsf_count) {
                $failedFinancials++;
            }
        }

        // If any financial limits failed...
        if ($failedFinancials > 0) {
            // Qualifies for Manual Review / Exceptions
            if ($this->manual_review_allowed || $this->exception_allowed) {
                return self::ENGINE_STATUS_REVIEW; // Manual Review Required
            }
            // Fails without exception
            return self::ENGINE_STATUS_GREYED_OUT; // Better Fit Later
        }

        // 6. Conditional Checks (App & Docs)
        $conditional = false;
        
        if ($this->require_completed_application) {
            $hasApp = FundingApplication::where('borrower_id', $borrower->id)->exists();
            if (!$hasApp) {
                $conditional = true;
            }
        }

        if ($this->require_documents_uploaded) {
            $hasDocs = BorrowerDocument::where('borrower_id', $borrower->id)->exists();
            if (!$hasDocs) {
                $conditional = true;
            }
        }

        if ($conditional) {
            return self::ENGINE_STATUS_WARNING; // Conditional
        }

        // 7. Passes everything
        return self::ENGINE_STATUS_ACTIVE;
    }

    public function getEngineStatusLabelAttribute()
    {
        $status = $this->getEngineStatusAttribute();
        switch ($status) {
            case self::ENGINE_STATUS_ACTIVE:
                return self::ENGINE_LABEL_ACTIVE;
            case self::ENGINE_STATUS_WARNING:
                return self::ENGINE_LABEL_WARNING;
            case self::ENGINE_STATUS_REVIEW:
                return self::ENGINE_LABEL_REVIEW;
            case self::ENGINE_STATUS_GREYED_OUT:
                return self::ENGINE_LABEL_GREYED_OUT;
            case self::ENGINE_STATUS_HIDDEN:
                return self::ENGINE_LABEL_HIDDEN;
            default:
                return self::ENGINE_LABEL_LOCKED;
        }
    }
}
