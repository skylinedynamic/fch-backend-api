<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundingApplicationCoBorrower extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'funding_application_id',
        'first_name',
        'last_name',
        'title_role',
        'ownership_percentage',
        'email',
        'mobile_phone',
        'home_phone',
        'date_of_birth',
        'ssn',
        'street_address',
        'city',
        'state',
        'zip_code',
        'drivers_license_number',
        'drivers_license_state',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'ownership_percentage' => 'decimal:2',
    ];

    /**
     * Get the funding application that owns the co-borrower.
     */
    public function fundingApplication()
    {
        return $this->belongsTo(FundingApplication::class);
    }
}
