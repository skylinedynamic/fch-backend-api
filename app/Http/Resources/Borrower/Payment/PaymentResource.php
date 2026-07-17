<?php

namespace App\Http\Resources\Borrower\Payment;

use Illuminate\Http\Request;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
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
        $timezone = $request->header('x-timezone', config('app.timezone', 'UTC'));

        return [
            'id' => $this->id,
            'transaction_date' => $this->transaction_date ? \Carbon\Carbon::parse($this->transaction_date)->setTimezone($timezone)->format('Y-m-d') : null,
            'from' => $this->payment_from ?? __('payment.na'),
            'payment_type' => $this->payment_type ?? __('payment.na'),
            'company' => $this->getCompanyName(),
            'details' => $this->getPaymentDetails(),
            'invoice_id' => $this->invoice?->invoice_id ?? $this->invoice_id,
            'invoice_no' => $this->invoice?->invoice_id,
            'transaction_id' => $this->transaction_id,
            'amount' => $this->amount,
            'displayable_amount' => currencyFormat($this->amount, 2, $this->currency_symbol ?? '$', $this->conversion_rate ?? 1),
            'currency' => $this->currency,
            'status' => strtoupper($this->status),
            'is_environment' => (bool)$this->is_environment,
            'borrower_id' => $this->borrower_id,
            'partner_id' => $this->partner_id,
            'entity_id' => $this->entity_id,
            'entity_type' => $this->entity_type,
            'borrower_name' => ($this->entity_type === Borrower::class && $this->borrower) ? ($this->borrower->first_name . ' ' . $this->borrower->last_name) : '',
            'partner_name' => ($this->entity_type === Partner::class && $this->partner) ? ($this->partner->full_name ?? '') : '',
            'name' => $this->borrower ? ($this->borrower->first_name . ' ' . $this->borrower->last_name) : '',
            'email' => $this->borrower?->email,
            'phone' => $this->borrower?->phone_number,
            'created_at' => $this->created_at ? \Carbon\Carbon::parse($this->created_at)->setTimezone($timezone)->format('Y-m-d H:i:s') : null,
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
        if ($this->invoice) {
            return __('payment.invoice') . ' ' . $this->invoice->invoice_id;
        }

        // Fallback for older data or missing relations
        $invoice = Invoice::where('entity_id', $this->entity_id)
            ->where('entity_type', $this->entity_type)
            ->where('status', Invoice::STATUS_PAID)
            ->first();

        if ($invoice) {
            return __('payment.invoice') . ' ' . ($invoice->invoice_id ?? $invoice->id);
        }

        return $this->description ?? __('payment.payment');
    }
}
