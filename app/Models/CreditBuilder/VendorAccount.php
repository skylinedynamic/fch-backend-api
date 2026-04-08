<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vendor_accounts';

    protected $fillable = [
        'card_name',
        'issuer',
        'credit_category',
        'difficulty_rank',
        'difficulty_tier',
        'engine_eligibility_band',
        'approval_level',
        'typical_personal_fico_min',
        'typical_personal_fico_ideal',
        'time_in_business_min_months',
        'revenue_expectation',
        'business_credit_required',
        'pg_required',
        'cash_based',
        'velocity_sensitive',
        'relationship_required',
        'internal_notes',
        'portal_tier_unlock',
        'borrower_exposure',
        'sequencing_priority',
        'image_url',
        'reference_url',
    ];

    protected $appends = ['full_image_url'];

    public function getFullImageUrlAttribute()
    {
        if ($this->image_url) {
            return url('storage/' . encodeUrl($this->image_url));
        }
        return null;
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!$search) return $query;

        return $query->where(function ($q) use ($search) {
            $q->where('card_name', 'LIKE', "%{$search}%")
              ->orWhere('issuer', 'LIKE', "%{$search}%")
              ->orWhere('approval_level', 'LIKE', "%{$search}%");
        });
    }
}
