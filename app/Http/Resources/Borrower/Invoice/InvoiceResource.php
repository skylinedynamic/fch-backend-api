<?php

namespace App\Http\Resources\Borrower\Invoice;

use App\Models\Invoice\Invoice;
use Carbon\Carbon;
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
        $timezone = $request->header('x-timezone', config('app.timezone', 'UTC'));

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'borrower_id' => $this->borrower_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'customer_name' => $this->customer_name,
            'email' => $this->email,
            'country' => $this->country,
            'payment_amount' => (float) $this->payment_amount,
            'displayable_payment_amount' => currencyFormat($this->payment_amount, 2, $this->currency_symbol ?? '$', $this->conversion_rate ?? 1),
            'displayable_amount' => currencyFormat($this->payment_amount, 2, $this->currency_symbol ?? '$', $this->conversion_rate ?? 1),
            'payment_date' => toDateOnly($this->payment_date),
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d'),
            'status' => $this->status,
            'type' => $this->getFormattedType(),
            'details' => $this->details,
        ];
    }

    /**
     * Get human readable formatted invoice type safely without triggering N+1 queries.
     */
    protected function getFormattedType(): string
    {
        if (empty($this->type)) {
            return '';
        }

        $isEnrollmentType = $this->type === Invoice::INVOICE_TYPE_ENROLLMENT;
        $subscription = $this->relationLoaded('subscription') ? $this->subscription : null;

        if ($isEnrollmentType && $subscription && $subscription->down_payment > 0 && (int) $subscription->installment_type === 1) {
            return ucwords(Invoice::INVOICE_TYPE_ENROLLMENT) . ' (' . ucwords(str_replace('_', ' ', Invoice::INVOICE_TYPE_DOWN_PAYMENT)) . ')';
        }

        return ucwords(str_replace('_', ' ', (string) $this->type));
    }
}
