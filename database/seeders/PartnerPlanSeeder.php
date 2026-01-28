<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Partner\PartnerPlan;

class PartnerPlanSeeder extends Seeder
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
                'payment_amount' => 4997,
                'installments_count' => null,
                'total_installments_count' => null,
                'total_amount' => 4997,
                'platform_fee' => 299,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'currency_rate' => 1.0000,
                'status' => true
            ],
            [
                'partner_type' => 'Certified Partner',
                'partner_type_slug' => Str::slug('Certified Partner'),
                'enrollment_type' => 'Payment Plan (11-month plan)',
                'enrollment_type_slug' => Str::slug('Payment Plan (11-month plan)'),
                'payment_amount' => 499.70,
                'installments_count' => 1,
                'total_installments_count' => 11,
                'total_amount' => 5496.70,
                'platform_fee' => 299,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'currency_rate' => 1.0000,
                'status' => true
            ],

            // White-Label Partner
            [
                'partner_type' => 'White-Label Partner (WL)',
                'partner_type_slug' => Str::slug('White-Label Partner (WL)'),
                'enrollment_type' => 'One Time Payment',
                'enrollment_type_slug' => Str::slug('One Time Payment'),
                'payment_amount' => 6997,
                'installments_count' => null,
                'total_installments_count' => null,
                'total_amount' => 6997,
                'platform_fee' => 399,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'currency_rate' => 1.0000,
                'status' => true
            ],
            [
                'partner_type' => 'White-Label Partner (WL)',
                'partner_type_slug' => Str::slug('White-Label Partner (WL)'),
                'enrollment_type' => 'Payment Plan (11-month plan)',
                'enrollment_type_slug' => Str::slug('Payment Plan (11-month plan)'),
                'payment_amount' => 699.70,
                'installments_count' => 1,
                'total_installments_count' => 11,
                'total_amount' => 7696.70,
                'platform_fee' => 399,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'currency_rate' => 1.0000,
                'status' => true
            ],
        ];

        PartnerPlan::insert($plans);
    }
}
