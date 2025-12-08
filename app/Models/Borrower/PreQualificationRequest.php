<?php

namespace App\Models\Borrower;

use App\Models\Business\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Company\Company;

class PreQualificationRequest extends Model
{
    use SoftDeletes;

    protected $table = 'pre_qualification_requests';

    protected $fillable = [
        'borrower_id',
        'business_id',
        'first_name',
        'last_name',
        'business_website',
        'email',
        'phone_number',
        'state_of_incorporation',
        'business_name',
        'industry_type',
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

    public const ON_HOLD = 'on_hold';
    public const SUBMITTED = 'submitted';
    public const INTAKE_RECEIVED = 'intake_received';
    public const IN_REVIEW = 'in_review';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    

    public const STATUS = [
        self::ON_HOLD => self::ON_HOLD,
        self::SUBMITTED => self::SUBMITTED,
        self::INTAKE_RECEIVED => self::INTAKE_RECEIVED,
        self::IN_REVIEW => self::IN_REVIEW,
        self::APPROVED => self::APPROVED,
        self::DECLINED => self::DECLINED,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Scope a query to search across common text fields.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (!$term) {
            return $query;
        }

        $like = "%{$term}%";

        return $query->where(function (Builder $q) use ($like) {
            $q->where('full_name', 'like', $like)
              ->orWhere('business_name', 'like', $like)
              ->orWhere('email', 'like', $like)
              ->orWhere('phone', 'like', $like);
        });
    }
}
