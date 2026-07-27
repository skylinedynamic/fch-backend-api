<?php

namespace App\Services\FCH\Common\PaymentMethod;

use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentMethod;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use Illuminate\Support\Facades\Log;

class PaymentMethodService
{
    /**
     * Update or Create Settings for a partner.
     *
     * @param array $data
     * @return object
     */
    public function updateOrCreate(array $data): object
    {
        $partnerId = $data['partner_id'] ?? null;

        // Ensure partner_id exists for context
        if (!isset($data['partner_id']) && auth()->check()) {
            $partnerId = auth()->user()->partner_id;
        }

        // We'll iterate through the data and store it as key-value pairs
        // Exclude partner_id from the key-value pairs since it's a column
        $settingsData = collect($data)->except(['partner_id']);

        foreach ($settingsData as $key => $value) {
            PaymentMethod::updateOrCreate(
                [
                    'partner_id' => $partnerId,
                    'key' => $key,
                ],
                [
                    'value' => $value,
                ]
            );
        }

        // Return the reconstructed flat object
        return $this->getSettings($partnerId) ?: new \stdClass();
    }

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
        $flat->payment_mode = Payment::MODE_SANDBOX;
        $flat->stripe_key_sandbox = null;
        $flat->stripe_secret_sandbox = null;
        $flat->stripe_key_production = null;
        $flat->stripe_secret_production = null;
        $flat->payment_method = Payment::PAYMENT_FROM_STRIPE;
        $flat->status = true;
        $flat->configuration = null;
        $flat->partner_auto_charge_host_fee = 1;

        foreach ($methods as $method) {
            $flat->{$method->key} = $method->value;
        }

        $flat->partner_auto_charge_host_fee = (bool) filter_var($flat->partner_auto_charge_host_fee, FILTER_VALIDATE_BOOLEAN);

        return $flat;
    }

    public function getPaymentMethods(?int $partnerId = null): ?object
    {
        $query = PaymentMethod::query();
        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        } else {
            $query->whereNull('partner_id');
        }

        $methods = $query->get();

        $flat = new \stdClass();
        $flat->id = 1; // Dummy ID for backwards compatibility
        $flat->partner_id = $partnerId;

        // Define default fallback values to prevent "Undefined property" errors
        $flat->payment_mode = Payment::MODE_SANDBOX;
        $flat->stripe_key_sandbox = null;
        $flat->stripe_secret_sandbox = null;
        $flat->stripe_key_production = null;
        $flat->stripe_secret_production = null;
        $flat->payment_method = Payment::PAYMENT_FROM_STRIPE;
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
     * Update or Create Settings for a partner.
     *
     * @param array $data
     * @return object
     */
    public function updatePaymentMethods(array $data): object
    {
        $partnerId = $data['partner_id'] ?? null;

        // Ensure partner_id exists for context
        if (!isset($data['partner_id']) && auth()->check()) {
            $partnerId = auth()->user()->partner_id;
        }

        // We'll iterate through the data and store it as key-value pairs
        // Exclude partner_id from the key-value pairs since it's a column
        $settingsData = collect($data)->except(['partner_id']);

        foreach ($settingsData as $key => $value) {
            PaymentMethod::updateOrCreate(
                [
                    'partner_id' => $partnerId,
                    'key' => $key,
                ],
                [
                    'value' => $value,
                ]
            );
        }

        // Return the reconstructed flat object
        return $this->getSettings($partnerId) ?: new \stdClass();
    }

    /**
     * Get active payment providers configured for a partner.
     *
     * @param int|null $partnerId
     * @return array
     */
    public function getActiveProvidersForPartner(?int $partnerId = null): array
    {
        if (!$partnerId && auth()->check()) {
            $partnerId = auth()->user()?->partner_id;
        }

        $providers = PaymentProvider::query()
            ->where('status', true)
            ->whereHas('partnerPaymentProviders', function ($q) use ($partnerId) {
                $q->where('partner_id', $partnerId)
                    ->where('status', true);
            })
            ->with(['partnerPaymentProviders' => function ($q) use ($partnerId) {
                $q->where('partner_id', $partnerId)
                    ->where('status', true)
                    ->select([
                        'id',
                        'partner_id',
                        'payment_provider_id',
                        'status',
                        'is_default',
                    ]);
            }])
            ->get();

        return $providers
            ->map(function (PaymentProvider $provider) {
                $partnerProvider = $provider->partnerPaymentProviders->first();

                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'logo' => encodeUrl(asset($provider->logo)),
                    'status' => $provider->status,
                    'is_default' => $partnerProvider?->is_default ?? false,
                    'partner_payment_provider_id' => $partnerProvider?->id,
                    'configuration' => null,
                ];
            })
            ->sortByDesc('is_default')
            ->values()
            ->all();
    }
}