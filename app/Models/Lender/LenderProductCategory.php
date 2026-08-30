<?php

namespace App\Models\Lender;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class LenderProductCategory extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE   = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    protected $fillable = [
        'name',
        'status',
        'sort_order',
    ];
}
