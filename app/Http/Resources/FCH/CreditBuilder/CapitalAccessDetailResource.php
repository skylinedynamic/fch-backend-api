<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessDetailResource extends JsonResource
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
            'target_exposure_min' => $this->target_exposure_min,
            'target_exposure_max' => $this->target_exposure_max,
            'est_timeline_min' => $this->est_timeline_min,
            'est_timeline_max' => $this->est_timeline_max,
            'minimum_fico_requirement' => $this->minimum_fico_requirement,
            'maximum_utilization_threshold' => $this->maximum_utilization_threshold,
            'difficulty_modifier' => $this->difficulty_modifier,
            'status' => $this->status ?? 'Active',
            'what_you_will_get' => $this->what_you_will_get ? json_decode($this->what_you_will_get, true) : [],
            'pre_flight_checklist' => $this->pre_flight_checklist ? json_decode($this->pre_flight_checklist, true) : [],
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
                        'typical_personal_fico_min' => $account->typical_personal_fico_min,
                        'time_in_business_min_months' => $account->time_in_business_min_months,
                        'status' => $account->status ?? 'Active',
                        'pivot' => [
                            'sorting_order' => $account->pivot->sorting_order,
                            'why_this_card' => $account->pivot->why_this_card,
                            'est_limit_min' => $account->pivot->est_limit_min,
                            'est_limit_max' => $account->pivot->est_limit_max,
                            'est_limit_for_show' => currencyFormat($account->pivot->est_limit_min, 0) . ' - ' . currencyFormat($account->pivot->est_limit_max, 0),
                            'hard_pull' => $account->pivot->hard_pull,
                            'what_you_need' => $account->pivot->what_you_need ? json_decode($account->pivot->what_you_need, true) : [],
                            'access_time' => $account->pivot->access_time,
                            'access_day' => $account->pivot->access_day,
                            'access_day_time' => $account->pivot->access_day_time,
                        ],
                    ];
                })
            ),
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
        ];
    }
}
