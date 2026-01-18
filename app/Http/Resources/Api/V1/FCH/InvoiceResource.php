<?php

namespace App\Http\Resources\Api\V1\FCH;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
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
            'invoice_id' => $this->invoice_id,
            'partner_id' => $this->partner_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'customer_name' => $this->customer_name,
            'email' => $this->email,
            'country' => $this->country,
            'payment_amount' => $this->payment_amount,
            'payment_date' => $this->payment_date,
            'status' => $this->status,
            'cycle' => $this->cycle,
            'created_at' => $this->created_at,
            // Add any other fields or relations if needed
        ];
    }
}
