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
        'require_completed_application',
        'require_documents_uploaded',
        'manual_review_allowed',
        'exception_allowed',
        'allowed_documents',
    ];

    protected $casts = [
        'uses' => 'array',
        'qualify_reasons' => 'array',
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

    public function lenderVisibilities()
    {
        return $this->hasMany(LenderVisibility::class, 'funding_sequencing_id');
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

        if (!$borrower) {
            return self::ENGINE_STATUS_ACTIVE;
        }

        // Load lenders if not loaded
        if (!$this->relationLoaded('lenderVisibilities')) {
            $this->load('lenderVisibilities');
        }

        // If no lenders are configured, default to HIDDEN (or maybe ACTIVE if we want to fallback)
        // Let's fallback to ACTIVE if no lenders to not break existing data
        if ($this->lenderVisibilities->isEmpty()) {
            return self::ENGINE_STATUS_ACTIVE;
        }

        // Evaluate status for each lender and find the "best" status
        // Priority: ACTIVE (Eligible Now) > WARNING (Conditional) > REVIEW (Manual Review) > GREYED_OUT (Better Fit Later) > HIDDEN (Not Eligible)
        $statusPriority = [
            self::ENGINE_STATUS_ACTIVE => 5,
            self::ENGINE_STATUS_WARNING => 4,
            self::ENGINE_STATUS_REVIEW => 3,
            self::ENGINE_STATUS_GREYED_OUT => 2,
            self::ENGINE_STATUS_HIDDEN => 1,
        ];

        $bestStatus = self::ENGINE_STATUS_HIDDEN;
        $bestPriority = 0;

        foreach ($this->lenderVisibilities as $lenderSeq) {
            // Prevent lazy loading violation by explicitly setting the parent relation
            $lenderSeq->setRelation('fundingSequencing', $this);
            
            $status = $lenderSeq->getEngineStatusForBorrower($borrower);
            $priority = $statusPriority[$status] ?? 0;

            if ($priority > $bestPriority) {
                $bestPriority = $priority;
                $bestStatus = $status;
            }
        }

        return $bestStatus;
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
