<?php

namespace App\Http\Resources\FCH\Common\Payment;

use Illuminate\Http\Request;
use App\Models\User\User;
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
        $authUser = $request->user();
        $isPartnerPaying = (bool) $this->is_partner_paying || $this->entity_type === Partner::class;

        $rawDetails = $this->details;
        if (is_array($rawDetails)) {
            if (!empty($rawDetails['approved_at'])) {
                $rawDetails['approved_at'] = toTimezone($rawDetails['approved_at']);
            }
            if (isset($rawDetails['approved_by']) && is_numeric($rawDetails['approved_by'])) {
                $approver = User::find($rawDetails['approved_by']);
                if ($approver) {
                    $rawDetails['approved_by'] = $approver->name ?? $approver->full_name ?? $approver->email;
                }
            }
        }

        return [
            'id' => $this->id,
            'transaction_date' => toTimezone($this->transaction_date),
            'from' => $this->payment_from ?? __('payment.na'),
            'payment_type' => $this->payment_type ?? __('payment.na'),
            'transaction_type' => $this->getTransactionTypeForUser($authUser),
            'is_partner_paying' => $isPartnerPaying,
            'can_approve' => $this->canApproveForUser($authUser),
            'company' => $this->getCompanyName(),
            'details' => $this->getPaymentDetails(),
            'note' => is_array($this->details) ? ($this->details['approval_note'] ?? $this->details['note'] ?? null) : null,
            'rejection_reason' => is_array($this->details) ? ($this->details['rejection_reason'] ?? $this->details['reason'] ?? null) : null,
            'raw_details' => $rawDetails,
            'invoice_id' => $this->invoice?->invoice_id ?? $this->invoice_id,
            'invoice_no' => $this->invoice?->invoice_id,
            'transaction_id' => $this->transaction_id,
            'amount' => currencyFormat($this->amount, 2, $this->currency_symbol ?? '$', $this->conversion_rate ?? 1),
            'currency' => $this->currency,
            'status' => strtoupper($this->status),
            'is_environment' => (bool) $this->is_environment,
            'is_manual' => (bool) $this->is_manual,
            'borrower_id' => $this->borrower_id,
            'partner_id' => $this->partner_id,
            'entity_id' => $this->entity_id,
            'entity_type' => $this->entity_type,
            'borrower_name' => ($this->entity_type === Borrower::class && $this->borrower) ? ($this->borrower->first_name . ' ' . $this->borrower->last_name) : '',
            'partner_name' => ($this->entity_type === Partner::class && $this->partner) ? ($this->partner->full_name ?? '') : '',
            'name' => $this->entity ? ($this->entity->full_name ?? $this->entity->company_name ?? ($this->entity->first_name . ' ' . $this->entity->last_name)) : '',
            'email' => $this->entity?->email,
            'phone' => $this->entity?->phone_number,
            'address' => $this->entity?->address_line_1 ?? $this->entity?->business_address_line_1 ?? $this->entity?->postal_code ?? $this->entity?->business_postal_code,
            'created_at' => toTimezone($this->created_at),
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
