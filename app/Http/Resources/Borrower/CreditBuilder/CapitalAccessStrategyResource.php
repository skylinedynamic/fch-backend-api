<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessStrategyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recommendedId = $this->additional['recommended_id'] ?? null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->sub_title,
            'isRecommended' => $recommendedId === $this->id,
            'target_exposure' => currencyFormat($this->target_exposure_min) . ' - ' . currencyFormat($this->target_exposure_max),
            'est_timeline' => $this->est_timeline_min . ' - ' . $this->est_timeline_max . ' days',
            'approval_probability' => $this->approval_probability ? $this->approval_probability . '%' : '',
            'benefits' => is_string($this->what_you_will_get) ? json_decode($this->what_you_will_get, true) : ($this->what_you_will_get ?? []),
            'checklist' => is_string($this->pre_flight_checklist) ? json_decode($this->pre_flight_checklist, true) : ($this->pre_flight_checklist ?? []),
            'roadmap' => $this->relationLoaded('creditAccounts') ? $this->creditAccounts->map(function ($account, $index) {
                // Status: first step is Active, others are Locked
                $status = $index === 0 ? 'Active' : 'Locked';

                return [
                    'id' => $account->id,
                    'day' => $account->pivot->sorting_order ?? ($index + 1),
                    'issuer' => $account->issuer,
                    'card_name' => $account->card_name,
                    'exposure' => currencyFormat($account->pivot->est_limit_min ?? 0) . ' - ' . currencyFormat($account->pivot->est_limit_max ?? 0),
                    'status' => $status,
                    'why' => $account->pivot->why_this_card,
                    'approval_odds' => ($account->pivot->est_approval_odds ?? null) ? $account->pivot->est_approval_odds . '%' : '',
                    'hard_pull' => $account->pivot->hard_pull,
                    'requirements' => is_string($account->pivot->what_you_need) ? json_decode($account->pivot->what_you_need, true) : ($account->pivot->what_you_need ?? []),
                    'result' => null,
                ];
            }) : [],
        ];
    }
}
