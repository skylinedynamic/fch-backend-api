<?php

namespace App\Models\Lender;

use App\Models\Funding\LenderVisibility;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lender extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    protected $fillable = [
        'name',
        'legal_name',
        'product_categories',
        'contact_name',
        'contact_title',
        'contact_email',
        'contact_phone',
        'website',
        'submission_portal_link',
        'submission_method',
        'submission_email',
        'iso_status',
        'status',
        'internal_notes',
        'last_verified_date',
    ];

    protected $casts = [
        'product_categories' => 'array',
        'last_verified_date' => 'date',
    ];

    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('legal_name', 'like', '%' . $search . '%')
                  ->orWhere('contact_name', 'like', '%' . $search . '%')
                  ->orWhere('contact_email', 'like', '%' . $search . '%')
                  ->orWhere('contact_phone', 'like', '%' . $search . '%')
                  ->orWhere('iso_status', 'like', '%' . $search . '%');
                  
                // Search by product category name
                $categoryIds = LenderProductCategory::where('name', 'like', '%' . $search . '%')->pluck('id');
                if ($categoryIds->isNotEmpty()) {
                    foreach ($categoryIds as $id) {
                        $q->orWhereJsonContains('product_categories', (string)$id)
                          ->orWhereJsonContains('product_categories', (int)$id);
                    }
                }
            });
        }
        return $query;
    }

    public function visibilities()
    {
        return $this->hasMany(LenderVisibility::class);
    }
}
