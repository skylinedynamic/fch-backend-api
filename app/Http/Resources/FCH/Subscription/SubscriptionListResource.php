<?php

namespace App\Http\Resources\FCH\Subscription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;

class SubscriptionListResource extends JsonResource
{
    /**
     * Transform the resource into an array for subscription lists.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPartner = $this->entity_type === Partner::class || strtolower((string)$this->entity_type) === 'partner';
        $isBorrower = $this->entity_type === Borrower::class || strtolower((string)$this->entity_type) === 'borrower';

        $partner = $this->relationLoaded('partner') ? $this->getRelation('partner') : null;
        $borrower = $this->relationLoaded('borrower') ? $this->getRelation('borrower') : null;
        $user = $this->relationLoaded('user') ? $this->getRelation('user') : null;

        $subscriberName = null;
        if ($isPartner) {
            $subscriberName = $partner?->full_name ?? $this->partner_name ?? null;
        } elseif ($isBorrower) {
            $subscriberName = $borrower ? trim(($borrower->first_name ?? '') . ' ' . ($borrower->last_name ?? '')) : ($this->borrower_name ?? null);
        } else {
            $subscriberName = $user?->name ?? $user?->full_name ?? $this->partner_name ?? $this->borrower_name ?? null;
        }

        return [
            'id' => $this->id,
            'entity_type' => $this->entity_name,
            'subscriber_name' => $subscriberName,
            'enrollment_type' => $this->enrollment_type,
            'payable_amount' => currencyFormat($this->payable_amount),
            'subscription_start_date' => toTimezone($this->subscription_start_date),
            'subscription_end_date' => toTimezone($this->subscription_end_date),
            'enrollment_completed_at' => toTimezone($this->enrollment_completed_at),
            'status' => $this->status,
            'created_at' => toTimezone($this->created_at),
        ];
    }
}
