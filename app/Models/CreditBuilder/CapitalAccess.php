<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapitalAccess extends Model
{
    use HasFactory, SoftDeletes;

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
            'credit_account_id'
        )->withPivot('sorting_order', 'id')->orderByPivot('sorting_order', 'asc')->withTimestamps();
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
}
