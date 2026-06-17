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
            'access_day_time'
        )->orderByPivot('sorting_order', 'asc')->withTimestamps();
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
