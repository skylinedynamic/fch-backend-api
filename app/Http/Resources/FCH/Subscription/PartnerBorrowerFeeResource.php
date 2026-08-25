<?php

namespace App\Http\Resources\FCH\Subscription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerBorrowerFeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $borrowerFee = is_numeric($this->resource)
            ? (float) $this->resource
            : (float) ($this->borrower_fee_amount ?? $this->resource['borrower_fee_amount'] ?? 0);

        return [
            'borrower_fee_amount' => $borrowerFee,
            'borrower_fee_amount_formatted' => currencyFormat($borrowerFee),
        ];
    }
}
