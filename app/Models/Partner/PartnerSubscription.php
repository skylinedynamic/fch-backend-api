<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class PartnerSubscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'partner_id',
        'partner_type',
        'payment_type',
        'partner_url',
        'billing_term',
        'subscription_term_months',
        'first_reg_date',
        'first_reg_amount',
        'first_payment_amount',
        'first_reg_amount_2',
        'recurring_amount',
        'total_amount',
        'subscription_start_date',
        'subscription_end_date',
        'expiry_date',
        'termination_date',
        'status',
    ];

    protected $casts = [
        'first_reg_date' => 'date',
        'subscription_start_date' => 'date',
        'subscription_end_date' => 'date',
        'expiry_date' => 'date',
        'termination_date' => 'date',
        'first_reg_amount' => 'decimal:2',
        'first_payment_amount' => 'decimal:2',
        'first_reg_amount_2' => 'decimal:2',
        'recurring_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'subscription_term_months' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($subscription) {
            // Auto-calculate subscription_term_months based on payment_type
            if ($subscription->payment_type === 'Payment Plan (10-month plan)') {
                $subscription->subscription_term_months = 10;
            } elseif ($subscription->payment_type === 'One-Time Payment') {
                $subscription->subscription_term_months = 1;
            }

            // Auto-calculate subscription_end_date from start_date + term months
            if ($subscription->subscription_start_date && $subscription->subscription_term_months) {
                $startDate = Carbon::parse($subscription->subscription_start_date);
                $subscription->subscription_end_date = $startDate->copy()->addMonths($subscription->subscription_term_months)->format('Y-m-d');
            }

            // Auto-calculate first_reg_amount for Payment Plan
            // First Reg Amount = Monthly Platform Fee × Term
            if ($subscription->payment_type === 'Payment Plan (10-month plan)' && $subscription->recurring_amount) {
                $isFoundingOrExecutive = in_array($subscription->partner_type, [
                    'Founding Partner',
                    'Executive Partner (White-Label)'
                ]);
                
                if ($isFoundingOrExecutive) {
                    $subscription->first_reg_amount = $subscription->recurring_amount * ($subscription->subscription_term_months ?? 10);
                }
            }

            // Set first_reg_amount to 0 for Referral partners
            if ($subscription->partner_type === 'Referral') {
                $subscription->first_reg_amount = 0;
            }
        });
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
