<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sub_title' => $this->sub_title,
            'partner_id' => $this->partner_id,
            'target_exposure_min' => currencyFormat($this->target_exposure_min, 0),
            'target_exposure_max' => currencyFormat($this->target_exposure_max, 0),
            'est_timeline' => $this->est_timeline_min . ' - ' . $this->est_timeline_max . ' days',
            'minimum_fico_requirement' => $this->minimum_fico_requirement,
            'maximum_utilization_threshold' => $this->maximum_utilization_threshold,
            'difficulty_modifier' => $this->difficulty_modifier,
            'allow_recent_late_payment' => (bool) ($this->allow_recent_late_payment ?? true),
            'late_payment_lookback_months' => $this->late_payment_lookback_months,
            'status' => $this->status ?? 'Active',
            'credit_accounts' => $this->when(
                $this->relationLoaded('creditAccounts'),
                fn() => $this->creditAccounts->map(function ($account) {
                    return [
                        'id' => $account->id,
                        'card_name' => $account->card_name,
                        'issuer' => $account->issuer,
                        'credit_category' => $account->credit_category,
                        'image_url' => $account->image_url,
                        'full_image_url' => $account->full_image_url,
                    ];
                })
            ),
        ];
    }
}
