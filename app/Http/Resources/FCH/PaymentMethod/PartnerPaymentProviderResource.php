<?php

namespace App\Http\Resources\FCH\PaymentMethod;

use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;
use App\Support\Payment\PaymentCredentials;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerPaymentProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // This resource also wraps the unsaved stdClass placeholder that
        // PaymentMethodConfigurationService::getProviderConfig() returns for a
        // provider the partner has never configured, so it resolves statically
        // rather than through the model's helpers.
        $configuration = PaymentCredentials::toArray($this->configuration ?? []);
        $paymentMode = PaymentCredentials::mode($this->payment_mode ?? null, $configuration);

        unset($configuration['payment_mode']);

        $provider = PaymentProvider::find($this->payment_provider_id);
        $isBank = $provider && $provider->name === PaymentProvider::BANK_TRANSFER;

        return [
            'id' => $this->id ?? null,
            'partner_id' => $this->partner_id,
            'payment_provider_id' => $this->payment_provider_id,
            'payment_mode' => $paymentMode,
            'status' => (bool) ($this->status ?? false),
            'is_default' => (bool) ($this->is_default ?? false),
            'configuration' => empty($configuration) ? new \stdClass() : $configuration,
            'bank_details' => $isBank && $this->partner_id ? PaymentMethodBankDetail::where('partner_id', $this->partner_id)->get() : [],
        ];
    }
}
