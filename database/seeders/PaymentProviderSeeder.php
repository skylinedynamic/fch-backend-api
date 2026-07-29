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
                'logo' => 'payment_provider/stripe.png',
                'status' => true,
            ],
            [
                'name' => PaymentProvider::ECRYPT,
                'logo' => 'payment_provider/ecrytp.png',
                'status' => true,
            ],
            [
                'name' => PaymentProvider::SQUARE,
                'logo' => 'payment_provider/square.png',
                'status' => true,
            ],
            [
                'name' => PaymentProvider::AUTHORIZE_NET,
                'logo' => 'payment_provider/authorize.png',
                'status' => true,
            ],
            [
                'name' => PaymentProvider::CASH,
                'logo' => 'payment_provider/cash.png',
                'status' => true,
            ],
            [
                'name' => PaymentProvider::BANK_TRANSFER,
                'logo' => 'payment_provider/bank.png',
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
