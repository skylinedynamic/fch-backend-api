<?php

namespace App\Http\Resources\FCH\PaymentMethod;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerPaymentProviderListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Expects the partner's linkage to be eager-loaded and assigned to the
     * `linkage` attribute by the caller (see PartnerPaymentProviderController::index)
     * to avoid an N+1 query per provider.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $linkage = $this->linkage ?? null;
        $configuration = $linkage ? ($linkage->configuration ?? []) : [];
        if (is_object($configuration)) {
            $configuration = (array) $configuration;
        }
        // payment_mode lives on the linkage row; updateOrCreateConfig() strips it
        // out of the encrypted configuration JSON, so the column is the source of truth.
        $paymentMode = $linkage?->payment_mode
            ?? ($configuration['payment_mode'] ?? PaymentProvider::MODE_SANDBOX);

        $pubKey = null;
        if ($this->name === PaymentProvider::STRIPE) {
            if (is_array($configuration)) {
                $pubKey = $configuration[$paymentMode]['publishable_key']
                    ?? $configuration['publishable_key']
                    ?? $configuration['public_key']
                    ?? null;
            }
            if (!$pubKey && $request->user()) {
                $partnerId = $linkage?->partner_id ?? $request->user()->partner_id;
                if (!$partnerId && $request->user()->entity_type === Borrower::class) {
                    $partnerId = Borrower::find($request->user()->entity_id)?->partner_id;
                }
                if (!$partnerId) {
                    $partnerId = Partner::host()?->id;
                }
                if ($partnerId) {
                    $fallbackLinkage = PartnerPaymentProvider::where('partner_id', $partnerId)
                        ->where('payment_provider_id', $this->id)
                        ->where('status', true)
                        ->first();
                    $providerConfig = $fallbackLinkage?->configuration ?? [];
                    if (is_object($providerConfig)) {
                        $providerConfig = (array) $providerConfig;
                    }
                    $mode = $fallbackLinkage?->payment_mode
                        ?? ($providerConfig['payment_mode'] ?? PaymentProvider::MODE_SANDBOX);
                    $pubKey = $providerConfig[$mode]['publishable_key']
                        ?? $providerConfig['publishable_key']
                        ?? $providerConfig['public_key']
                        ?? null;
                }
            }
        }

        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'display_name'    => $this->display_name ?? $this->name,
            'slug'            => $this->slug ?? strtolower(str_replace([' ', '.'], '', $this->name)),
            'logo'            => $this->logo ? encodeUrl(asset($this->logo)) : null,
            'status'          => $linkage ? (bool) $linkage->status : false,
            'is_default'      => $linkage ? (bool) $linkage->is_default : false,
            'is_configured'   => $linkage ? (!empty($linkage->configuration)) : false,
            'payment_mode'    => $paymentMode,
            'publishable_key' => $pubKey,
        ];
    }
}
