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
     * @return Collection
     */
    public function getProviders(int $partnerId): Collection
    {
        $providers = PaymentProvider::where('status', true)
            ->with(['partnerPaymentProviders' => function ($query) use ($partnerId) {
                $query->where('partner_id', $partnerId);
            }])
            ->get();

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

        return $linkage ?: (object)[
            'id' => null,
            'partner_id' => $partnerId,
            'payment_provider_id' => $provider->id,
            'status' => false,
            'is_default' => false,
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

        $configuration = isset($data['configuration'])
            ? $data['configuration']
            : ($existingLinkage ? $existingLinkage->configuration : []);

        if (isset($data['payment_mode'])) {
            $configuration['payment_mode'] = $data['payment_mode'];
        }

        // If setting as default, clear other default configurations for this partner
        if ($isDefault) {
            PartnerPaymentProvider::where('partner_id', $partnerId)->update(['is_default' => false]);
        }

        return PartnerPaymentProvider::updateOrCreate(
            [
                'partner_id' => $partnerId,
                'payment_provider_id' => $providerId,
            ],
            [
                'status' => $status,
                'is_default' => $isDefault,
                'configuration' => $configuration,
            ]
        );
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
