<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Company\Company;

class PreQualificationRequest extends Model
{
    use SoftDeletes;

    protected $table = 'pre_qualification_requests';

    protected $fillable = [
        'user_id',
        'full_name',
        'business_website',
        'email',
        'phone',
        'state_of_incorporation',
        'business_name',
        'industry',
        'business_structure',
        'monthly_revenue',
        'annual_revenue',
        'time_in_business',
        'funding_requested_amount',
        'funding_urgency',
        'purpose_of_funding',
        'personal_credit_score',
        'estimated_business_credit_score',
        'existing_business_debt',
        'debt_balance',
        'notes',
        'status',
        'assign_to',
    ];

    protected $casts = [
        'monthly_revenue' => 'decimal:2',
        'annual_revenue' => 'decimal:2',
        'funding_requested_amount' => 'decimal:2',
        'debt_balance' => 'decimal:2',
        'existing_business_debt' => 'boolean',
    ];

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const PROCESSING = 'processing';
    public const DECLINED = 'declined';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
