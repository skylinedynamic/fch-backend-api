<?php

namespace App\Services\FCH\Common\PaymentMethod;

use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentMethod;
use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use Illuminate\Support\Facades\Log;

class PaymentMethodService
{

    /**
     * Get Settings for a partner as a flat object.
     *
     * @param int|null $partnerId
     * @return object|null
     */
    public function getSettings(?int $partnerId = null): ?object
    {
        // if ($partnerId) {
        //     $partner = \App\Models\Partner\Partner::find($partnerId);
        //     if ($partner && $partner->partner_type !== \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL && !$partner->is_host) {
        //         $host = \App\Models\Partner\Partner::where('is_host', 1)->first();
        //         if ($host) {
        //             $partnerId = $host->id;
        //         }
        //     }
        // }

        $query = PaymentMethod::query();

        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        } else {
            // For GlobalPaymentService when fetching global/admin configurations
            $query->whereNull('partner_id');
        }

        $methods = $query->get();

        $flat = new \stdClass();
        $flat->id = 1; // Dummy ID for backwards compatibility
        $flat->partner_id = $partnerId;

        // Define default fallback values to prevent "Undefined property" errors
        $flat->payment_mode = PaymentProvider::MODE_SANDBOX;
        $flat->stripe_key_sandbox = null;
        $flat->stripe_secret_sandbox = null;
        $flat->stripe_key_production = null;
        $flat->stripe_secret_production = null;
        $flat->payment_method = PaymentProvider::STRIPE;
        $flat->status = true;
        $flat->configuration = null;
        $flat->partner_auto_charge_host_fee = 1;

        foreach ($methods as $method) {
            $flat->{$method->key} = $method->value;
        }

        $flat->partner_auto_charge_host_fee = (bool) filter_var($flat->partner_auto_charge_host_fee, FILTER_VALIDATE_BOOLEAN);

        return $flat;
    }

    /**
     * Get active payment providers configured for a partner.
     *
     * @return array
     */
    public function getActiveProvidersForPartner(): array
    {
        $recipientPartnerId = null;

        if (auth()->user()?->entity_type == Partner::class) {
            $recipientPartnerId = Partner::host()->id;
        } else {
            $recipientPartnerId = auth()->user()?->partner_id;
        }

        $providers = PaymentProvider::query()
            ->where('status', true)
            ->whereHas('partnerPaymentProviders', function ($q) use ($recipientPartnerId) {
                $q->where('partner_id', $recipientPartnerId)
                    ->where('status', true);
            })
            ->with([
                'partnerPaymentProviders' => function ($q) use ($recipientPartnerId) {
                    $q->where('partner_id', $recipientPartnerId)
                        ->where('status', true)
                        ->select([
                            'id',
                            'partner_id',
                            'payment_provider_id',
                            'payment_mode',
                            'status',
                            'is_default',
                            'configuration',
                        ]);
                }
            ])
            ->get();

        return $providers
            ->map(function (PaymentProvider $provider) use ($recipientPartnerId) {
                $partnerProvider = $provider->partnerPaymentProviders->first();
                $publishableKey = null;
                $bankDetails = [];

                $rawMode = $partnerProvider?->payment_mode ?? ($partnerProvider?->configuration['payment_mode'] ?? PaymentProvider::MODE_SANDBOX);
                $environment = strtolower(trim((string) $rawMode));

                if ($provider->name === PaymentProvider::STRIPE && $partnerProvider) {
                    $config = $partnerProvider->configuration ?? [];
                    $envConfig = $config[$environment] ?? $config;
                    $publishableKey = $envConfig['publishable_key'] ?? ($config['stripe_key_' . $environment] ?? null);
                }

                if ($provider->name === PaymentProvider::BANK_TRANSFER && $partnerProvider) {
                    $bankDetails = PaymentMethodBankDetail::where('partner_id', $recipientPartnerId)
                        ->where(function ($q) use ($environment) {
                            $q->where('payment_mode', $environment)
                                ->orWhereNull('payment_mode');
                        })
                        ->where('status', true)
                        ->orderBy('is_default', 'desc')
                        ->get()
                        ->map(function ($bank) {
                            return [
                                'id' => $bank->id,
                                'bank_name' => $bank->bank_name,
                                'account_name' => $bank->account_name,
                                'account_number' => $bank->account_number,
                                'routing_number' => $bank->routing_number,
                                'instructions' => $bank->instructions,
                                'is_default' => $bank->is_default,
                            ];
                        })
                        ->toArray();
                }

                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'logo' => encodeUrl(asset($provider->logo)),
                    'status' => $provider->status,
                    'is_default' => $partnerProvider?->is_default ?? false,
                    'partner_payment_provider_id' => $partnerProvider?->id,
                    'publishable_key' => $publishableKey,
                    'configuration' => null,
                    'bank_details' => $bankDetails,
                ];
            })
            ->sortByDesc('is_default')
            ->values()
            ->all();
    }
}