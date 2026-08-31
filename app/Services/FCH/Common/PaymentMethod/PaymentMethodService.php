<?php

namespace App\Services\FCH\Common\PaymentMethod;

use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use Illuminate\Support\Facades\Log;

class PaymentMethodService
{

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