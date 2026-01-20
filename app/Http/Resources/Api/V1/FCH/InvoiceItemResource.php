<?php

namespace App\Http\Resources\Api\V1\FCH;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
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
            'description' => $this->description,
            'rate' => $this->rate,
            'quantity' => $this->quantity,
            'amount' => $this->amount,
        ];
    }
}
