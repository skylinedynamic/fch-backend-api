<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'credit_accounts';

    protected $fillable = [
        'card_name',
        'issuer',
        'credit_category',
        'typical_personal_fico_min',
        'time_in_business_min_months',
        'image_url',
        'reference_url',
        'status',
    ];

    protected $appends = ['full_image_url'];

    public function getFullImageUrlAttribute()
    {
        return $this->image_url ? encodeUrl(asset("storage/{$this->image_url}")) : null;
    }

    public function getImageAttribute($value)
    {
        return $value ? encodeUrl(asset("storage/{$value}")) : null;
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!$search) return $query;

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
