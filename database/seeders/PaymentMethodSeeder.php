<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $service = app(\App\Services\FCH\Common\PaymentMethod\PaymentMethodService::class);
        $service->updateOrCreate([
            'partner_id' => 1,
            'payment_mode' => 'sandbox',
            'stripe_key_sandbox' => 'pk_test_51SqomQITiXOK676KqcF5x6Nm2noawfxC3mkXDsWYTDqPEENwGGzAVomhzTQ0Uq5EtOJWka94JiEk7zaWp0hPYP1x00hx8z5p27',
            'stripe_secret_sandbox' => 'sk_test_51SqomQITiXOK676KRaIiltDuTmMXpDk2SbS6WGcydWjLAHO2MsnmEqyJOUFqgdAt8QLN4Hl0yJg7x6JZEkLYNXJb00PGw644fm',
            'status' => true,
            'stripe_key_production' => null,
            'stripe_secret_production' => null,
            'payment_method' => 'stripe',
            'configuration' => json_encode([
                'notifications' => true,
                'theme' => 'light',
            ]),
        ]);

        $this->command->info('Settings seeded successfully');
    }
}
