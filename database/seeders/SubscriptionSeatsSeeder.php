<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Partner\Partner;

class SubscriptionSeatsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Update all subscriptions for Partners where seats or amount is null
        DB::table('subscriptions')
            ->where('entity_type', Partner::class)
            ->where(function ($query) {
                $query->whereNull('user_seats')
                      ->orWhereNull('per_seat_amount');
            })
            ->update([
                'user_seats' => DB::raw('COALESCE(user_seats, 3)'),
                'per_seat_amount' => DB::raw('COALESCE(per_seat_amount, 49.00)'),
            ]);
    }
}
