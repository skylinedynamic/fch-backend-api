<?php

namespace App\Http\Resources\FCH\Lead;

use Illuminate\Http\Request;
use App\Http\Resources\FCH\Borrower\BorrowerResource;
use Illuminate\Http\Resources\Json\JsonResource;

class ConvertedBorrowerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'borrower' => new BorrowerResource($this),
            'invoice' => $this->latestInvoice ? [
                'id' => $this->latestInvoice->id,
                'invoice_id' => $this->latestInvoice->invoice_id,
            ] : null,
        ];
    }
}
