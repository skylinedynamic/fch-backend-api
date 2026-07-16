<?php

namespace App\Models\Funding;

use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use App\Models\User\User;
use App\Services\Borrower\Funding\FundingReadinessService;
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
    const ENGINE_STATUS_LOCKED     = 'Locked';

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
        'readiness_factors',
        'missing_documents',
        'icon',
        'icon_color',
        'status',
        'sort_order',
        'action_url',
        'require_completed_application',
        'require_documents_uploaded',
        'manual_review_allowed',
        'exception_allowed',
        'allowed_documents',
    ];

    protected $casts = [
        'uses' => 'array',
        'readiness_factors' => 'array',
        'missing_documents' => 'array',
        'allowed_documents' => 'array',
        'require_completed_application' => 'boolean',
        'require_documents_uploaded' => 'boolean',
        'manual_review_allowed' => 'boolean',
        'exception_allowed' => 'boolean',
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
        return $this->belongsTo(LenderProductCategory::class, 'funding_type_id');
    }

    public function scopeSearch($query, $term)
    {
        if (empty($term)) {
            return $query;
        }

        $cleanTerm = str_replace(['$', ','], '', $term);

        return $query->where(function ($q) use ($term, $cleanTerm) {
            $q->where('name', 'like', '%' . $term . '%')
              ->orWhere('subtitle', 'like', '%' . $term . '%')
              ->orWhere('description', 'like', '%' . $term . '%')
              ->orWhere('terms', 'like', '%' . $term . '%')
              ->orWhere('status', 'like', '%' . $term . '%')
              ->orWhereHas('group', function ($q) use ($term) {
                  $q->where('name', 'like', '%' . $term . '%');
              })
              ->orWhereHas('type', function ($q) use ($term) {
                  $q->where('name', 'like', '%' . $term . '%');
              });

            $q->orWhere('amount_range', 'like', '%' . $term . '%');
        });
    }

    public function lenderVisibilities()
    {
        return $this->hasMany(LenderVisibility::class, 'funding_sequencing_id');
    }

    public function getMinFicoAttribute() { return $this->getMinLenderRequirement('min_fico'); }
    public function getMinBusinessCreditScoreAttribute() { return $this->getMinLenderRequirement('min_business_credit_score'); }
    public function getMinTibMonthsAttribute() { return $this->getMinLenderRequirement('min_tib_months'); }
    public function getMinMonthlyRevenueAttribute() { return $this->getMinLenderRequirement('min_monthly_revenue'); }
    public function getMinMonthlyDepositsAttribute() { return $this->getMinLenderRequirement('min_monthly_deposits'); }
    public function getMinAverageDailyBalanceAttribute() { return $this->getMinLenderRequirement('min_average_daily_balance'); }
    public function getMinDscrAttribute() { return $this->getMinLenderRequirement('min_dscr'); }
    public function getMaxNsfCountAttribute() { return $this->getMaxLenderRequirement('max_nsf_count'); }
    public function getMaxNegativeDaysAttribute() { return $this->getMaxLenderRequirement('max_negative_days'); }

    protected function getMinLenderRequirement($field)
    {
        if (!$this->relationLoaded('lenderVisibilities')) {
            $this->load('lenderVisibilities');
        }
        $activeVisibilities = $this->lenderVisibilities->filter(function ($v) {
            if (!$v->relationLoaded('lender')) $v->load('lender');
            return $v->lender && $v->lender->status !== Lender::STATUS_INACTIVE;
        });

        if ($activeVisibilities->isEmpty()) {
            return null;
        }
        if ($activeVisibilities->containsStrict($field, null)) {
            return null;
        }
        return $activeVisibilities->min($field);
    }

    protected function getMaxLenderRequirement($field)
    {
        if (!$this->relationLoaded('lenderVisibilities')) {
            $this->load('lenderVisibilities');
        }
        $activeVisibilities = $this->lenderVisibilities->filter(function ($v) {
            if (!$v->relationLoaded('lender')) $v->load('lender');
            return $v->lender && $v->lender->status !== Lender::STATUS_INACTIVE;
        });

        if ($activeVisibilities->isEmpty()) {
            return null;
        }
        if ($activeVisibilities->containsStrict($field, null)) {
            return null;
        }
        return $activeVisibilities->max($field);
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

        // 3. Get Authenticated Borrower or Admin Requested Borrower
        $borrower = null;
        if (request()->has('borrower_id') && auth()->check() && in_array(auth()->user()->user_type, [\App\Models\User\User::ADMIN, \App\Models\User\User::EMPLOYEE, \App\Models\User\User::PARTNER])) {
            $borrower = \App\Models\Borrower\Borrower::find(request()->get('borrower_id'));
        } elseif (auth()->check() && auth()->user() && auth()->user()->user_type === \App\Models\User\User::BORROWER && auth()->user()->borrower) {
            $borrower = auth()->user()->borrower;
        }

        if (!$borrower) {
            return self::ENGINE_STATUS_ACTIVE;
        }

        // Load lenders if not loaded
        if (!$this->relationLoaded('lenderVisibilities')) {
            $this->load('lenderVisibilities');
        }

        // If no lenders are configured, default to LOCKED
        if ($this->lenderVisibilities->isEmpty()) {
            $bestStatus = self::ENGINE_STATUS_LOCKED;
        } else {
            // Evaluate status for each lender and find the most restrictive status
            // Priority: HIDDEN (Not Eligible) > GREYED_OUT (Better Fit Later) > REVIEW (Manual Review) > WARNING (Conditional) > ACTIVE (Eligible Now)
            $statusPriority = [
                self::ENGINE_STATUS_HIDDEN => 5,
                self::ENGINE_STATUS_GREYED_OUT => 4,
                self::ENGINE_STATUS_REVIEW => 3,
                self::ENGINE_STATUS_WARNING => 2,
                self::ENGINE_STATUS_ACTIVE => 1,
            ];

            $worstStatus = self::ENGINE_STATUS_ACTIVE;
            $highestPriority = 0;
            $hasActiveLender = false;

            foreach ($this->lenderVisibilities as $lenderSeq) {
                if (!$lenderSeq->relationLoaded('lender')) {
                    $lenderSeq->load('lender');
                }
                
                if ($lenderSeq->lender && $lenderSeq->lender->status === \App\Models\Lender\Lender::STATUS_INACTIVE) {
                    continue; // Skip inactive lenders
                }

                $hasActiveLender = true;

                // Prevent lazy loading violation by explicitly setting the parent relation
                $lenderSeq->setRelation('fundingSequencing', $this);
                
                $status = $lenderSeq->getEngineStatusForBorrower($borrower);
                $priority = $statusPriority[$status] ?? 0;

                if ($priority > $highestPriority) {
                    $highestPriority = $priority;
                    $worstStatus = $status;
                }
            }

            if (!$hasActiveLender) {
                $bestStatus = self::ENGINE_STATUS_HIDDEN;
            } else {
                $bestStatus = $worstStatus;
            }
        }


        // If currently evaluated as ACTIVE, let's also check Readiness Factors
        if ($bestStatus === self::ENGINE_STATUS_ACTIVE) {
            $readinessService = app(FundingReadinessService::class);
            $missingDocs = $readinessService->getMissingDocuments($this, $borrower);
            
            $qualificationService = app(\App\Services\FCH\Common\Funding\FundingQualificationService::class);
            $factors = $qualificationService->evaluateReadinessFactors($borrower, $this->id);
            
            $hasMissing = collect($factors)->contains('status', config('funding_qualification.statuses.missing', 'Missing'));
            
            if ($hasMissing) {
                $bestStatus = self::ENGINE_STATUS_GREYED_OUT;
            }
        }

        return $bestStatus;
    }

    /**
     * Get the reasons why this funding sequence is restricted (e.g., Hidden).
     *
     * @param \App\Models\Borrower\Borrower|null $borrower
     * @return array
     */
    public function getEngineStatusReasonsForBorrower($borrower = null): array
    {
        if (!$borrower) {
            return [];
        }

        $reasons = [];

        foreach ($this->lenderVisibilities as $lenderSeq) {
            if (!$lenderSeq->relationLoaded('lender')) {
                $lenderSeq->load('lender');
            }
            if ($lenderSeq->lender && $lenderSeq->lender->status === \App\Models\Lender\Lender::STATUS_INACTIVE) {
                continue; // Skip inactive lenders
            }

            $lenderSeq->setRelation('fundingSequencing', $this);
            if ($lenderSeq->getEngineStatusForBorrower($borrower) === self::ENGINE_STATUS_HIDDEN) {
                $lenderReasons = $lenderSeq->getHiddenReasonsForBorrower($borrower);
                foreach ($lenderReasons as $reason) {
                    $reasons[$reason['label']] = $reason;
                }
            }
        }

        return array_values($reasons);
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
            case self::ENGINE_STATUS_LOCKED:
                return self::ENGINE_LABEL_LOCKED;
            default:
                return 'Unknown';
        }
    }
}
