<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting\Setting;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::updateOrCreate(
            [

                'payment_mode' => 'sandbox',
                'stripe_key_sandbox' => 'pk_test_51SqomQITiXOK676KqcF5x6Nm2noawfxC3mkXDsWYTDqPEENwGGzAVomhzTQ0Uq5EtOJWka94JiEk7zaWp0hPYP1x00hx8z5p27',
                'stripe_secret_sandbox' => 'sk_test_51SqomQITiXOK676KRaIiltDuTmMXpDk2SbS6WGcydWjLAHO2MsnmEqyJOUFqgdAt8QLN4Hl0yJg7x6JZEkLYNXJb00PGw644fm',
                'status' => true,
                'stripe_key_production' => null,
                'stripe_secret_production' => null,
                'non_host_enrollment_price' => 800,
                'payment_method' => 'stripe',
                'configuration' => [
                    'notifications' => true,
                    'theme' => 'light',
                ],
            ]
        );

        $this->command->info('Settings seeded successfully');
    }
}
