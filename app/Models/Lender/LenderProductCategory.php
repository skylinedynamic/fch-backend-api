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

    /**
     * The Preferred / Additional Funding Types an application offers: the
     * Active categories, in their order - the same list a lender is set up
     * with, so an application's types are the categories it is matched on.
     *
     * @return array<int, string>
     */
    public static function fundingTypeNames(): array
    {
        return self::where('status', self::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
