<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Partner\Setting;
use App\Models\Partner\Partner;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure we attach to an existing partner, preferably ID 1 or the first available
        $partnerId = Partner::first()?->id ?? 1;

        if (!$partnerId) {
            $this->command->warn('No partners found. Skipping SettingsSeeder.');
            return;
        }

        Setting::updateOrCreate(
            ['partner_id' => $partnerId],
            [
                'card_name_sandbox' => 'Test User',
                'card_no_sandbox' => '4242 4242 4242 4242',
                'card_expiry_sandbox' => '12/30',
                'card_cvv_sandbox' => '123',
                'card_name_production' => 'Live User',
                'card_no_production' => '1111 2222 3333 4444',
                'card_expiry_production' => '12/35',
                'card_cvv_production' => '999',
                'currency' => 'USD',
                'status' => 'active',
                'payment_mode' => 'sandbox',
                'stripe_key_sandbox' => 'pk_test_51SqomQITiXOK676KqcF5x6Nm2noawfxC3mkXDsWYTDqPEENwGGzAVomhzTQ0Uq5EtOJWka94JiEk7zaWp0hPYP1x00hx8z5p27',
                'stripe_secret_sandbox' => 'sk_test_51SqomQITiXOK676KRaIiltDuTmMXpDk2SbS6WGcydWjLAHO2MsnmEqyJOUFqgdAt8QLN4Hl0yJg7x6JZEkLYNXJb00PGw644fm',
                'stripe_key_production' => null,
                'stripe_secret_production' => null,
                'payment_method' => 'credit_card',
                'configuration' => [
                    'notifications' => true,
                    'theme' => 'light',
                ],
            ]
        );

        $this->command->info('Settings seeded for Partner ID: ' . $partnerId);
    }
}
