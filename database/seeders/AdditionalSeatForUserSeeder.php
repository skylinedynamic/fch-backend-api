<?php

namespace Database\Seeders;

use App\Models\Partner\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdditionalSeatForUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'partner_type' => 'White-Label Partner (WL)',
                'partner_type_slug' => 'white-label-partner-wl',
                'group' => 'user_seat',
                'entity_type' => null,
                'enrollment_type' => 'Monthly',
                'enrollment_type_slug' => Str::slug('Monthly'),
                'total_seat_count' => 3,
                'total_amount' => 49.00,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'partner_type' => 'Certified Partner',
                'partner_type_slug' => 'certified-partner',
                'group' => 'user_seat',
                'entity_type' => null,
                'enrollment_type' => 'Monthly',
                'enrollment_type_slug' => Str::slug('Monthly'),
                'total_seat_count' => 3,
                'total_amount' => 49.00,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1.0000,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('subscription_plans')->insert($plans);
    }
}
