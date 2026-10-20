<?php

namespace App\Http\Resources\FCH\PaymentMethod;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Support\Payment\PaymentSetup;
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
        $configuration = $linkage ? $linkage->configurationArray() : [];
        $paymentMode = $linkage ? $linkage->paymentMode() : PaymentProvider::MODE_SANDBOX;

        $pubKey = null;
        if ($this->name === PaymentProvider::STRIPE) {
            $pubKey = $linkage?->publishableKey();

            if (!$pubKey && $request->user()) {
                $partnerId = $linkage?->partner_id ?? $request->user()->partner_id;
                if (!$partnerId && $request->user()->entity_type === Borrower::class) {
                    $partnerId = Borrower::find($request->user()->entity_id)?->partner_id;
                }
                if (!$partnerId) {
                    $partnerId = Partner::host()?->id;
                }
                if ($partnerId) {
                    $pubKey = PartnerPaymentProvider::where('partner_id', $partnerId)
                        ->where('payment_provider_id', $this->id)
                        ->where('status', true)
                        ->first()?->publishableKey();
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
            'is_configured'   => !empty($configuration),
            'payment_mode'    => $paymentMode,
            'publishable_key' => $pubKey,
            // What the method is still missing for its active mode; empty when
            // it's set up (or was never set up). can_take_payments says whether
            // payers are offered it meanwhile.
            'setup_issues'    => PaymentSetup::issues($linkage, $this->name),
            'can_take_payments' => PaymentSetup::isReady($linkage, $this->name),
        ];
    }
}
