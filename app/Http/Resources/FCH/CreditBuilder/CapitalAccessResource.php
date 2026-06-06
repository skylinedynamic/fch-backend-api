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
            'id'              => $this->id,
            'name'            => $this->name,
            'description'     => $this->description,
            'status'          => $this->status ?? 'Active',
            'credit_accounts' => $this->when(
                $this->relationLoaded('creditAccounts'),
                fn() => $this->creditAccounts->map(fn($account) => [
                    'id'                          => $account->id,
                    'card_name'                   => $account->card_name,
                    'issuer'                      => $account->issuer,
                    'credit_category'             => $account->credit_category,
                    'typical_personal_fico_min'   => $account->typical_personal_fico_min,
                    'time_in_business_min_months' => $account->time_in_business_min_months,
                    'image_url'                   => $account->image_url,
                    'full_image_url'              => $account->full_image_url,
                    'status'                      => $account->status ?? 'Active',
                    'sorting_order'               => $account->pivot->sorting_order,
                ])
            ),
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
        ];
    }
}
