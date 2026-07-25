<?php

namespace App\Http\Resources\FCH\Common\SubscriptionPlan;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    /**
     * Installment calculator constraints surfaced to the frontend.
     */
    private const INSTALLMENT_MIN_MONTHS = 2;
    private const INSTALLMENT_MAX_MONTHS = 24;
    private const INSTALLMENT_DEFAULT_DOWN_PAYMENT = 0.0;

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $isInstallment = $this->isInstallmentPlan();

        return [
            'id' => $this->id,
            'partner_type' => $this->partner_type,
            'partner_type_slug' => $this->partner_type_slug,
            'enrollment_type' => $this->enrollment_type,
            'enrollment_type_slug' => $this->enrollment_type_slug,
            'payment_amount' => (float) $this->payment_amount,
            'total_installments_count' => $this->total_installments_count,
            'total_amount' => (float) $this->total_amount,
            'platform_fee' => (float) $this->platform_fee,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'currency_rate' => (float) $this->currency_rate,
            'status' => (bool) $this->status,
            // Installment support flags for the frontend calculator
            'supports_installment' => $isInstallment,
            'is_installment' => $isInstallment,
            'installment_min_months' => self::INSTALLMENT_MIN_MONTHS,
            'installment_max_months' => self::INSTALLMENT_MAX_MONTHS,
            'installment_default_months' => $this->total_installments_count ?: 12,
            'installment_default_down_payment' => self::INSTALLMENT_DEFAULT_DOWN_PAYMENT,
        ];
    }
}
