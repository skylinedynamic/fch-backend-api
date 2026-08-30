<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMethod\PaymentMethod;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\Partner\Partner;

class PartnerPaymentProviderSeeder extends Seeder
{
    /**
     * Run the database seeds to migrate configurations from payment_methods to partner_payment_providers.
     */
    public function run(): void
    {
        // 1. Resolve Stripe provider master record
        $stripeProvider = PaymentProvider::where('name', PaymentProvider::STRIPE)->first();

        if (!$stripeProvider) {
            $stripeProvider = PaymentProvider::create([
                'name' => PaymentProvider::STRIPE,
                'logo' => 'assets/payment_providers/stripe.svg',
                'status' => true,
            ]);
        }

        // 2. Get distinct partner_ids from payment_methods table
        $partnerIds = PaymentMethod::select('partner_id')->distinct()->pluck('partner_id');

        $hostPartner = Partner::where('is_host', true)->first();

        foreach ($partnerIds as $partnerId) {
            $targetPartnerId = $partnerId ?: ($hostPartner?->id);

            if (!$targetPartnerId) {
                continue;
            }

            // Fetch all key-value rows for this partner (handle both partner_id and null)
            $query = PaymentMethod::query();
            if ($partnerId === null) {
                $query->whereNull('partner_id');
            } else {
                $query->where('partner_id', $partnerId);
            }
            $methods = $query->get();

            $items = [];
            foreach ($methods as $m) {
                $keyLower = strtolower(trim($m->key));
                if (in_array($keyLower, ['stripe', strtolower(PaymentProvider::STRIPE)], true)) {
                    $jsonConfig = json_decode($m->value, true);
                    if (is_array($jsonConfig)) {
                        foreach ($jsonConfig as $jk => $jv) {
                            $items[strtolower(trim($jk))] = $jv;
                        }
                    }
                } else {
                    $items[$keyLower] = $m->value;
                }
            }

            $rawMode = $items['payment_mode'] ?? $items['paymentmode'] ?? $items['mode'] ?? $items['environment'] ?? $items['stripe_mode'] ?? 'sandbox';
            $paymentMode = strtolower(trim((string) $rawMode)) === 'production' ? 'production' : 'sandbox';

            $configuration = [
                'payment_mode' => $paymentMode,
                'sandbox' => [
                    'publishable_key' => $items['stripe_key_sandbox'] ?? null,
                    'secret_key' => $items['stripe_secret_sandbox'] ?? null,
                ],
                'production' => [
                    'publishable_key' => $items['stripe_key_production'] ?? null,
                    'secret_key' => $items['stripe_secret_production'] ?? null,
                ],
            ];

            PartnerPaymentProvider::updateOrCreate(
                [
                    'partner_id' => $targetPartnerId,
                    'payment_provider_id' => $stripeProvider->id,
                ],
                [
                    'status' => filter_var($items['status'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'is_default' => true,
                    'payment_mode' => $paymentMode,
                    'configuration' => $configuration,
                ]
            );
        }

        if ($this->command) {
            $this->command->info('Partner payment providers configuration seeded from payment_methods table successfully.');
        }
    }
}
