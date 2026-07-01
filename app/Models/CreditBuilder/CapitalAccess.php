<?php

namespace App\Models\CreditBuilder;

use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapitalAccess extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    const HARD_PULL_YES = 'Yes';
    const HARD_PULL_NO = 'No';

    // Access Time Constants
    const ACCESS_TIME_MORNING = 'Morning';
    const ACCESS_TIME_EVENING = 'Evening';
    const ACCESS_TIME_ANYTIME = 'Anytime';
    const ACCESS_TIME_DAY     = 'Day';

    // Pre-flight checklist task labels
    const TASK_BUSINESS_INFORMATION  = 'Business Information';
    const TASK_EIN_VERIFIED          = 'EIN Verified';
    const TASK_PERSONAL_CREDIT_PULLED = 'Personal Credit Pulled';
    const TASK_BUSINESS_BANK_ACCOUNT = 'Business Bank Account';
    const TASK_IDENTITY_VERIFIED     = 'Identity Verified';
    const TASK_NO_RECENT_DEROGATORIES = 'No Recent Derogatories';

    protected $table = 'capital_accesses';

    protected $guarded = ['id'];

    /**
     * Get the credit accounts associated with this capital access.
     * Uses the capital_access_sequences pivot table with sorting_order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function creditAccounts()
    {
        return $this->belongsToMany(
            CreditAccount::class,
            'capital_access_sequences',
            'capital_access_id',
        )->withPivot(
                'sorting_order',
                'id',
                'why_this_card',
                'est_limit_min',
                'est_limit_max',
                'hard_pull',
                'what_you_need',
                'access_time',
                'access_day',
                'access_day_time',
                'minimum_fico_requirement',
                'maximum_utilization_threshold',
                'difficulty_modifier'
            )->orderByPivot('sorting_order', 'asc')->withTimestamps();
    }

    /**
     * Get the capital access sequences (pivot rows) directly as a first-class relation.
     * Each sequence contains card-specific requirements, modifiers, and sort order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sequences()
    {
        return $this->hasMany(CapitalAccessSequence::class, 'capital_access_id')
            ->orderBy('sorting_order', 'asc');
    }

    /**
     * Scope search for filtering capital accesses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $search
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
                ->orWhere('status', 'LIKE', "%{$search}%");
        });
    }


    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Get the partner associated with this capital access.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
