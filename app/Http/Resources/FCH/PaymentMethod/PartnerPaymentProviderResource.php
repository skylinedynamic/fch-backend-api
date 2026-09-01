<?php

namespace App\Http\Resources\FCH\PaymentMethod;

use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;
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
        $configuration = $this->configuration ?? [];
        if (is_object($configuration)) {
            $configuration = (array) $configuration;
        }
        $paymentMode = $this->payment_mode ?? ($configuration['payment_mode'] ?? PaymentProvider::MODE_SANDBOX);

        if (is_array($configuration)) {
            unset($configuration['payment_mode']);
        }

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
