<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use Illuminate\Database\Seeder;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Partner\Partner;

class NewSubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'partner_type' => 'Certified Partner',
                'partner_type_slug' => 'certified-partner',
                'enrollment_type' => 'Installment',
                'enrollment_type_slug' => 'installment',
                'entity_type' => Partner::class,
                'is_partner_paying' => false,
                'payment_amount' => null,
                'subscription_period_month' => null,
                'total_installments_count' => null,
                'total_amount' => 5496.70,
                'platform_fee' => 299.00,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.00,
                'status' => true,
            ],
            [
                'partner_type' => 'White-Label Partner (WL)',
                'partner_type_slug' => 'white-label-partner-wl',
                'enrollment_type' => 'Installment',
                'enrollment_type_slug' => 'installment',
                'entity_type' => Partner::class,
                'is_partner_paying' => false,
                'payment_amount' => null,
                'subscription_period_month' => null,
                'total_installments_count' => null,
                'total_amount' => 7696.70,
                'platform_fee' => 399.00,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.00,
                'status' => true,
            ],
            [
                'partner_type' => '',
                'partner_type_slug' => '',
                'enrollment_type' => 'Installment',
                'enrollment_type_slug' => 'installment',
                'entity_type' => Borrower::class,
                'is_partner_paying' => false,
                'payment_amount' => null,
                'subscription_period_month' => null,
                'total_installments_count' => null,
                'total_amount' => 3000.00,
                'platform_fee' => null,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.00,
                'status' => true,
            ],
        ];

        SubscriptionPlan::insert($plans);
    }
}
