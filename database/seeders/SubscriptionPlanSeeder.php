<?php

namespace Database\Seeders;

use Illuminate\Support\Str;
use App\Models\Partner\Partner;
use Illuminate\Database\Seeder;
use App\Models\Borrower\Borrower;
use App\Models\Partner\SubscriptionPlan;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            // Certified Partner
            [
                'partner_type' => 'Certified Partner',
                'partner_type_slug' => Str::slug('Certified Partner'),
                'enrollment_type' => 'One Time Payment',
                'enrollment_type_slug' => Str::slug('One Time Payment'),
                'entity_type' => Partner::class,
                'is_partner_paying' => false,
                'payment_amount' => 4997,
                'subscription_period_month' => 11,
                'total_installments_count' => null,
                'total_amount' => 4997,
                'platform_fee' => 299,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000, //conversion rate
                'status' => true
            ],
            [
                'partner_type' => 'Certified Partner',
                'partner_type_slug' => Str::slug('Certified Partner'),
                'enrollment_type' => 'Payment Plan (11-month plan)',
                'enrollment_type_slug' => Str::slug('Payment Plan (11-month plan)'),
                'entity_type' => Partner::class,
                'is_partner_paying' => false,
                'payment_amount' => 499.70,
                'subscription_period_month' => 11,
                'total_installments_count' => 11,
                'total_amount' => 5496.70,
                'platform_fee' => 299,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000,
                'status' => true
            ],

            // White-Label Partner
            [
                'partner_type' => 'White-Label Partner (WL)',
                'partner_type_slug' => Str::slug('White-Label Partner (WL)'),
                'enrollment_type' => 'One Time Payment',
                'enrollment_type_slug' => Str::slug('One Time Payment'),
                'entity_type' => Partner::class,
                'is_partner_paying' => false,
                'payment_amount' => 6997,
                'subscription_period_month' => 11,
                'total_installments_count' => null,
                'total_amount' => 6997,
                'platform_fee' => 399,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000,
                'status' => true
            ],
            [
                'partner_type' => 'White-Label Partner (WL)',
                'partner_type_slug' => Str::slug('White-Label Partner (WL)'),
                'enrollment_type' => 'Payment Plan (11-month plan)',
                'enrollment_type_slug' => Str::slug('Payment Plan (11-month plan)'),
                'entity_type' => Partner::class,
                'is_partner_paying' => false,
                'payment_amount' => 699.70,
                'subscription_period_month' => 11,
                'total_installments_count' => 11,
                'total_amount' => 7696.70,
                'platform_fee' => 399,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000,
                'status' => true
            ],
            [
                'partner_type' => null,
                'partner_type_slug' => null,
                'enrollment_type' => 'One Time Payment',
                'enrollment_type_slug' => Str::slug('One Time Payment'),
                'entity_type' => Borrower::class,
                'is_partner_paying' => false,
                'payment_amount' => 2497,
                'subscription_period_month' => null,
                'total_installments_count' => null,
                'total_amount' => 2497,
                'platform_fee' => null,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000, //conversion rate
                'status' => true
            ],
            [
                'partner_type' => null,
                'partner_type_slug' => null,
                'enrollment_type' => '6 Month Payment',
                'enrollment_type_slug' => Str::slug('6 Month Payment'),
                'entity_type' => Borrower::class,
                'is_partner_paying' => false,
                'payment_amount' => 500,
                'subscription_period_month' => null,
                'total_installments_count' => 6,
                'total_amount' => 3000,
                'platform_fee' => null,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000, //conversion rate
                'status' => true
            ],
            [
                'partner_type' => null,
                'partner_type_slug' => null,
                'enrollment_type' => 'Fixed enrollment',
                'enrollment_type_slug' => Str::slug('Fixed enrollment'),
                'entity_type' => Borrower::class,
                'is_partner_paying' => true,
                'payment_amount' => 800,
                'subscription_period_month' => null,
                'total_installments_count' => null,
                'total_amount' => 800,
                'platform_fee' => null,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000, //conversion rate
                'status' => true
            ],

        ];

        SubscriptionPlan::insert($plans);
    }
}
