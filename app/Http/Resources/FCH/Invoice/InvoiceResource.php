<?php

namespace App\Http\Resources\FCH\Invoice;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'borrower_id' => $this->borrower_id,
            'partner_id' => $this->partner_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'customer_name' => $this->customer_name,
            'email' => $this->email,
            'country' => $this->country,
            'payment_amount' => $this->payment_amount,
            'payment_date' => $this->payment_date ? Carbon::parse($this->payment_date)->format('Y-m-d') : null,
            'status' => $this->status,
            'cycle' => $this->cycle,
            'details' => $this->details,
            // 'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d') : null,
            // Add any other fields or relations if needed
        ];
    }
}
