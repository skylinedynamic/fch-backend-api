<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMethod\PaymentProvider;

class PaymentProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $providers = [
            [
                'name' => PaymentProvider::STRIPE,
                'logo' => '',
                'status' => true,
            ],
            [
                'name' => PaymentProvider::ECRYPT,
                'logo' => '',
                'status' => true,
            ],
            [
                'name' => PaymentProvider::SQUARE,
                'logo' => '',
                'status' => true,
            ],
            [
                'name' => PaymentProvider::AUTHORIZE_NET,
                'logo' => '',
                'status' => true,
            ],
        ];

        foreach ($providers as $provider) {
            PaymentProvider::updateOrCreate(
                ['name' => $provider['name']],
                [
                    'logo' => $provider['logo'],
                    'status' => $provider['status'],
                ]
            );
        }

        if ($this->command) {
            $this->command->info('Payment providers seeded successfully.');
        }
    }
}
