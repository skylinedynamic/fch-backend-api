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
        'status',
    ];

    protected $casts = [
        'requirements' => 'array',
        'benefits' => 'array',
    ];
}
