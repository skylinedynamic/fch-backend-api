<?php

namespace App\Http\Resources\FCH\Common\Payment;

use Illuminate\Http\Request;
use App\Models\Invoice\Invoice;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
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
            'date' => $this->transaction_date ? $this->transaction_date->format('Y-m-d') : null,
            'from' => $this->payment_from ?? __('payment.na'),
            'payment_type' => $this->payment_type ?? __('payment.na'),
            'company' => $this->getCompanyName(),
            'details' => $this->getPaymentDetails(),
            'transaction_id' => $this->transaction_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => strtoupper($this->status),
            'created_at' => $this->created_at,
        ];
    }

    private function getCompanyName(): string
    {
        if ($this->borrower) {
            return $this->borrower->business_name ?? ($this->borrower->first_name . ' ' . $this->borrower->last_name);
        }
        if ($this->partner) {
            return $this->partner->company_name ?? $this->partner->full_name;
        }
        return __('payment.na');
    }

    private function getPaymentDetails(): string
    {
        // Try to find if there's an invoice linked
        $invoice = Invoice::where('entity_id', $this->entity_id)
            ->where('entity_type', $this->entity_type)
            ->where('status', 'Paid')
            ->first();

        if ($invoice) {
            return __('payment.invoice') . ' ' . ($invoice->invoice_id ?? $invoice->id);
        }

        return $this->description ?? __('payment.payment');
    }
}
