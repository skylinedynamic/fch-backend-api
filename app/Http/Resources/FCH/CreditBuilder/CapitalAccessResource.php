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
        $isIndex = $request->route() && $request->route()->getActionMethod() === 'index';

        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'sub_title'            => $this->sub_title,
            'partner_id'           => $this->partner_id,
            'target_exposure_min'  => currencyFormat($this->target_exposure_min, 0),
            'target_exposure_max'  => currencyFormat($this->target_exposure_max, 0),
            'approval_probability' => $this->approval_probability ? $this->approval_probability . '%' : null,
            'est_timeline_min'     => $this->est_timeline_min,
            'est_timeline_max'     => $this->est_timeline_max,
            'status'               => $this->status ?? 'Active',
            
            $this->mergeWhen(!$isIndex, [
                'what_you_will_get'    => $this->what_you_will_get ? json_decode($this->what_you_will_get, true) : [],
                'pre_flight_checklist' => $this->pre_flight_checklist ? json_decode($this->pre_flight_checklist, true) : [],
            ]),

            'credit_accounts' => $this->when(
                $this->relationLoaded('creditAccounts'),
                fn() => $this->creditAccounts->map(function($account) use ($isIndex) {
                    $data = [
                        'id'                          => $account->id,
                        'card_name'                   => $account->card_name,
                        'issuer'                      => $account->issuer,
                        'credit_category'             => $account->credit_category,
                        'image_url'                   => $account->image_url,
                        'full_image_url'              => $account->full_image_url,
                    ];

                    if (!$isIndex) {
                        $data['typical_personal_fico_min'] = $account->typical_personal_fico_min;
                        $data['time_in_business_min_months'] = $account->time_in_business_min_months;
                        $data['status'] = $account->status ?? 'Active';
                        $data['pivot'] = [
                            'sorting_order'     => $account->pivot->sorting_order,
                            'why_this_card'     => $account->pivot->why_this_card,
                            'est_approval_odds' => $account->pivot->est_approval_odds,
                            'est_limit_min'     => $account->pivot->est_limit_min,
                            'est_limit_max'     => $account->pivot->est_limit_max,
                            'hard_pull'         => $account->pivot->hard_pull,
                            'what_you_need'     => $account->pivot->what_you_need,
                        ];
                    }

                    return $data;
                })
            ),
            'created_at' => $this->when(!$isIndex, fn() => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null),
            'updated_at' => $this->when(!$isIndex, fn() => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null),
        ];
    }
}
