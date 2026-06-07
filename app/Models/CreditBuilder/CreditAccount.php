<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditAccount extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    protected $table = 'credit_accounts';

    protected $guarded = ['id'];

    protected $appends = ['full_image_url'];

    /**
     * Get the full URL for the credit account image.
     *
     * @return string|null
     */
    public function getFullImageUrlAttribute()
    {
        return $this->image_url ? encodeUrl(asset("storage/{$this->image_url}")) : null;
    }

    /**
     * Get the applications associated with the credit account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function applications()
    {
        return $this->hasMany(CreditAccountApplication::class);
    }

    /**
     * Scope search for filtering credit accounts.
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
            $q->where('card_name', 'LIKE', "%{$search}%")
                ->orWhere('issuer', 'LIKE', "%{$search}%")
                ->orWhere('credit_category', 'LIKE', "%{$search}%")
                ->orWhere('status', 'LIKE', "%{$search}%")
                ->orWhere('typical_personal_fico_min', 'LIKE', "%{$search}%")
                ->orWhere('time_in_business_min_months', 'LIKE', "%{$search}%");
        });
    }
}
