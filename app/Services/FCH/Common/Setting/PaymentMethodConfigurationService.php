<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\PaymentMethod\PaymentProvider;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use Illuminate\Support\Collection;

class PaymentMethodConfigurationService
{
    /**
     * Get active payment providers list with partner linkages.
     *
     * @param int $partnerId
     * @param bool $activeOnly
     * @return Collection
     */
    public function getProviders(int $partnerId, bool $activeOnly = true): Collection
    {
        $query = PaymentProvider::where('status', true);

        // if ($activeOnly) {
        //     $query->whereHas('partnerPaymentProviders', function ($q) use ($partnerId) {
        //         $q->where('partner_id', $partnerId)->where('status', true);
        //     });
        // }

        $providers = $query->with([
            'partnerPaymentProviders' => function ($query) use ($partnerId) {
                $query->where('partner_id', $partnerId);
            }
        ])->get();

        $providers->each(function ($provider) {
            $provider->linkage = $provider->partnerPaymentProviders->first();
        });

        return $providers;
    }

    /**
     * Get linkage details/configuration for a specific payment provider.
     *
     * @param int $partnerId
     * @param int $providerId
     * @return object
     */
    public function getProviderConfig(int $partnerId, int $providerId): object
    {
        $provider = PaymentProvider::findOrFail($providerId);

        $linkage = PartnerPaymentProvider::where('partner_id', $partnerId)
            ->where('payment_provider_id', $providerId)
            ->first();

        return $linkage ?: (object) [
            'id' => null,
            'partner_id' => $partnerId,
            'payment_provider_id' => $provider->id,
            'status' => false,
            'is_default' => false,
            'payment_mode' => 'sandbox',
            'configuration' => new \stdClass()
        ];
    }

    /**
     * Store or update provider configuration.
     *
     * @param int $partnerId
     * @param array $data
     * @return PartnerPaymentProvider
     */
    public function updateOrCreateConfig(int $partnerId, array $data): PartnerPaymentProvider
    {
        $id = $data['id'] ?? null;
        $providerId = $data['payment_provider_id'];
        $status = $data['status'] ?? true;
        $isDefault = $data['is_default'] ?? false;

        // Find existing record to preserve configuration if not provided
        $existingLinkage = null;
        if ($id) {
            $existingLinkage = PartnerPaymentProvider::where('partner_id', $partnerId)
                ->where('id', $id)
                ->first();
        }
        if (!$existingLinkage) {
            $existingLinkage = PartnerPaymentProvider::where('partner_id', $partnerId)
                ->where('payment_provider_id', $providerId)
                ->first();
        }

        $paymentMode = $data['payment_mode'] ?? ($existingLinkage ? $existingLinkage->payment_mode : PaymentProvider::MODE_SANDBOX);

        $provider = PaymentProvider::find($providerId);
        $isBank = $provider && $provider->name === PaymentProvider::BANK_TRANSFER;
        $isCash = $provider && $provider->name === PaymentProvider::CASH;

        if ($isBank || $isCash) {
            $configuration = null;
        } else {
            $configuration = isset($data['configuration'])
                ? $data['configuration']
                : ($existingLinkage ? $existingLinkage->configuration : null);

            if (is_array($configuration)) {
                unset($configuration['payment_mode']);
            }
        }

        // If setting as default, clear other default configurations for this partner
        if ($isDefault) {
            PartnerPaymentProvider::where('partner_id', $partnerId)->update(['is_default' => false]);
        }

        $linkage = PartnerPaymentProvider::updateOrCreate(
            [
                'partner_id' => $partnerId,
                'payment_provider_id' => $providerId,
            ],
            [
                'status' => $status,
                'is_default' => $isDefault,
                'payment_mode' => $paymentMode,
                'configuration' => $configuration,
            ]
        );

        if ($isBank && isset($data['bank_details'])) {
            $incomingDetails = $data['bank_details'];
            $incomingIds = collect($incomingDetails)->pluck('id')->filter()->toArray();

            // Delete existing bank details that are not in the payload
            \App\Models\PaymentMethod\PaymentMethodBankDetail::where('partner_id', $partnerId)
                ->whereNotIn('id', $incomingIds)
                ->delete();

            foreach ($incomingDetails as $detail) {
                $isDefaultBank = filter_var($detail['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $paymentMode = $detail['payment_mode'] ?? PaymentProvider::MODE_SANDBOX;

                if ($isDefaultBank) {
                    \App\Models\PaymentMethod\PaymentMethodBankDetail::where('partner_id', $partnerId)
                        ->where('payment_mode', $paymentMode)
                        ->update(['is_default' => false]);
                }

                \App\Models\PaymentMethod\PaymentMethodBankDetail::updateOrCreate(
                    [
                        'id' => $detail['id'] ?? null,
                        'partner_id' => $partnerId,
                    ],
                    [
                        'bank_name' => $detail['bank_name'],
                        'account_name' => $detail['account_name'],
                        'account_number' => $detail['account_number'],
                        'routing_number' => $detail['routing_number'] ?? null,
                        'instructions' => $detail['instructions'] ?? null,
                        'payment_mode' => $paymentMode,
                        'is_default' => $isDefaultBank,
                        'status' => filter_var($detail['status'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    ]
                );
            }
        }

        return $linkage;
    }

    /**
     * Update the active status of a provider.
     *
     * @param int $partnerId
     * @param array $data
     * @return PartnerPaymentProvider
     */
    public function updateStatus(int $partnerId, array $data): PartnerPaymentProvider
    {
        $providerId = $data['payment_provider_id'];
        $status = $data['status'];

        $linkage = PartnerPaymentProvider::where('partner_id', $partnerId)
            ->where('payment_provider_id', $providerId)
            ->first();

        if ($linkage) {
            $linkage->status = $status;
            $linkage->save();
        } else {
            $linkage = PartnerPaymentProvider::create([
                'partner_id' => $partnerId,
                'payment_provider_id' => $providerId,
                'status' => $status,
                'is_default' => false,
                'configuration' => [],
            ]);
        }

        return $linkage;
    }
}
