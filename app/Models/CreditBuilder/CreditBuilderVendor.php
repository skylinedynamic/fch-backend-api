<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditBuilderVendor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'credit_builder_vendors';

    protected $fillable = [
        'name',
        'category',
        'requirements',
        'benefits',
        'report_to',
        'tier_id',
        'general_url',
        'affiliate_url',
        'description',
        'status',
    ];

    protected $casts = [
        'requirements' => 'array',
        'benefits' => 'array',
        'report_to' => 'array',
        'general_url' => 'string',
        'affiliate_url' => 'string',
        'description' => 'string',
    ];

    public const STATUS_ACTIVE = 'Active';
    public const STATUS_INACTIVE = 'Inactive';

    public const STATUS = [
        self::STATUS_ACTIVE =>  self::STATUS_ACTIVE,
        self::STATUS_INACTIVE => self::STATUS_INACTIVE,
    ];

    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
              ->orWhere('category', 'LIKE', "%{$search}%")
              ->orWhere('requirements', 'LIKE', "%{$search}%")
              ->orWhere('benefits', 'LIKE', "%{$search}%")
              ->orWhere('report_to', 'LIKE', "%{$search}%")
              ->orWhere('tier_id', 'LIKE', "%{$search}%")
              ->orWhere('status', 'LIKE', "%{$search}%");
        });
    }
}
