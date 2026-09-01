<?php

namespace App\Services\FCH\Admin\PaymentMethod;

use App\Models\PaymentMethod\PaymentProvider;

class PaymentMethodService
{
    /**
     * List all payment providers.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllProviders()
    {
        return PaymentProvider::all();
    }

    /**
     * Update a payment provider.
     *
     * @param int $id
     * @param array $data
     * @return PaymentProvider
     */
    public function updateProvider($id, array $data)
    {
        $provider = PaymentProvider::findOrFail($id);

        if (isset($data['status'])) {
            $provider->status = filter_var($data['status'], FILTER_VALIDATE_BOOLEAN);
        }

        $provider->save();

        return $provider;
    }
}
