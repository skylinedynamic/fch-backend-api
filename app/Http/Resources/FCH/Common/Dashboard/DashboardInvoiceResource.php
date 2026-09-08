<?php

namespace App\Http\Resources\FCH\Common\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardInvoiceResource extends JsonResource
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
            'status' => $this->status,
            'payment_amount' => $this->payment_amount,
            'payment_date' => toDateOnly($this->payment_date),
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'conversion_rate' => $this->conversion_rate,
            'formatted_created_at' => $this->created_at?->format('M d, Y'),
        ];
    }
}
