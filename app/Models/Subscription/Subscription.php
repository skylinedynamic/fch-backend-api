<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerPlan;
use App\Models\Borrower\Borrower;

class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';
    const STATUS_SUSPENDED = 'Suspended';
    const STATUS_CANCELLED = 'Cancelled';
    const STATUS_PENDING = 'Pending';

    protected $table = 'subscriptions';

    protected $fillable = [
        'partner_id',
        'borrower_id',
        'partner_plan_id',
        'partner_type',
        'enrollment_type',
        'monthly_platform_fee',
        'total_subscription_amount',
        'payable_amount',
        'first_payment_date',
        'platform_fee_start_date',
        'next_installment_date',
        'installment_type',
        'subscription_start_date',
        'subscription_end_date',
        'status',
    ];

    protected $casts = [
        'first_payment_date' => 'date',
        'platform_fee_start_date' => 'date',
        'next_installment_date' => 'date',
        'subscription_start_date' => 'date',
        'subscription_end_date' => 'date',
        'monthly_platform_fee' => 'decimal:2',
        'total_subscription_amount' => 'decimal:2',
        'payable_amount' => 'decimal:2',
        'installment_type' => 'integer',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function partnerPlan()
    {
        return $this->belongsTo(PartnerPlan::class, 'partner_plan_id');
    }

    /**
     * Scope: apply text search across common subscription fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('status', 'like', "%{$search}%")
                ->orWhere('enrollment_type', 'like', "%{$search}%")
                ->orWhereHas('partner', function ($pq) use ($search) {
                    $pq->where('full_name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->orWhereHas('borrower', function ($bq) use ($search) {
                    $bq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        });
    }
}
