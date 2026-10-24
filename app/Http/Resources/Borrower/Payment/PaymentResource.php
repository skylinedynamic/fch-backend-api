<?php

namespace App\Http\Resources\Borrower\Payment;

use Illuminate\Http\Request;
use App\Models\User\User;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * The `details` fields a borrower may see: who verified the payment and
     * how, and where a manual payment went. Everything else in the column -
     * a card payment's gateway PaymentIntent (client_secret, customer and
     * charge ids), what was sent to the gateway, internal ids - stays server
     * side (BUG-062).
     */
    private const BORROWER_DETAIL_FIELDS = [
        'provider', 'bank_name', 'account_name', 'account_number', 'instructions', 'transaction_number',
        'decision', 'note', 'approval_note', 'approved_at', 'approved_by',
        'reason', 'rejection_reason', 'rejected_at', 'rejected_by',
        'error',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $request->header('x-timezone', config('app.timezone', 'UTC'));
        $rawDetails = $this->borrowerDetails();
        if (is_array($rawDetails)) {
            if (!empty($rawDetails['approved_at'])) {
                $rawDetails['approved_at'] = toTimezone($rawDetails['approved_at']);
            }
            if (!empty($rawDetails['rejected_at'])) {
                $rawDetails['rejected_at'] = toTimezone($rawDetails['rejected_at']);
            }
            if (isset($rawDetails['approved_by']) && is_numeric($rawDetails['approved_by'])) {
                $approver = User::find($rawDetails['approved_by']);
                if ($approver) {
                    $rawDetails['approved_by'] = $approver->name ?? $approver->full_name ?? $approver->email;
                }
            }
            if (isset($rawDetails['rejected_by']) && is_numeric($rawDetails['rejected_by'])) {
                $rejecter = User::find($rawDetails['rejected_by']);
                if ($rejecter) {
                    $rawDetails['rejected_by'] = $rejecter->name ?? $rejecter->full_name ?? $rejecter->email;
                }
            }
        }

        return [
            'id' => $this->id,
            'transaction_date' => $this->transaction_date ? \Carbon\Carbon::parse($this->transaction_date)->setTimezone($timezone)->format('Y-m-d') : null,
            'from' => $this->payment_from ?? __('payment.na'),
            'payment_type' => $this->payment_type ?? __('payment.na'),
            'transaction_type' => $this->getTransactionTypeForUser($request->user()),
            'company' => $this->getCompanyName(),
            'details' => $this->getPaymentDetails(),
            'note' => is_array($this->details) ? ($this->details['approval_note'] ?? $this->details['note'] ?? null) : null,
            'rejection_reason' => is_array($this->details) ? ($this->details['rejection_reason'] ?? $this->details['reason'] ?? null) : null,
            'raw_details' => $rawDetails,
            'invoice_id' => $this->invoice?->invoice_id ?? $this->invoice_id,
            'invoice_no' => $this->invoice?->invoice_id,
            'transaction_id' => $this->transaction_id,
            'amount' => $this->amount,
            'displayable_amount' => currencyFormat($this->amount, 2, $this->currency_symbol ?? '$', $this->conversion_rate ?? 1),
            'currency' => $this->currency,
            'status' => strtoupper($this->status),
            'is_environment' => (bool)$this->is_environment,
            'is_manual' => (bool)$this->is_manual,
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

    /**
     * The payer's company only - the list shows it beside the person's name, so
     * this never falls back to that name. A borrower's is their business; any
     * other payer (a partner, or a user paying on a partner's account) carries
     * the partner's.
     */
    private function getCompanyName(): ?string
    {
        $company = $this->entity_type === Borrower::class
            ? $this->borrower?->business_name
            : $this->partner?->company_name;

        return $company ?: null;
    }

    /**
     * The readable part of `details`: the allowed fields that hold plain
     * values, and the account a manual payment was paid into by its last four.
     */
    private function borrowerDetails(): array
    {
        if (!is_array($this->details)) {
            return [];
        }

        $details = array_filter(
            array_intersect_key($this->details, array_flip(self::BORROWER_DETAIL_FIELDS)),
            fn ($value) => is_scalar($value) && $value !== ''
        );

        if (isset($details['account_number'])) {
            $details['account_number'] = '****' . substr((string) $details['account_number'], -4);
        }

        return $details;
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
