<?php

namespace App\Http\Resources\FCH\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use App\Models\Employee\Employee;
use App\Models\Subscription\Subscription;
use App\Models\Invoice\Invoice;

class BillingResource extends JsonResource
{
    /**
     * What a row bills (the `billing_row` column BillingService selects): the
     * subscription's own cycle, or - on the host's list - the borrower fee a
     * partner owes for the client whose subscription carries the fee invoice.
     */
    public const ROW_SUBSCRIPTION = 'subscription';
    public const ROW_BORROWER_FEE = 'borrower_fee';

    /** A borrower fee is collected from the partner, so only the partner's status stops it. */
    public static function borrowerFeePausedReason(Subscription $row): ?string
    {
        $partner = $row->relationLoaded('borrower') ? $row->borrower?->partner : null;

        return ($partner && $partner->status !== Partner::STATUS_ACTIVE)
            ? __('billing.partner_not_active', ['status' => $partner->status])
            : null;
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        if ($this->billing_row === self::ROW_BORROWER_FEE) {
            return $this->borrowerFeeRow();
        }

        $partner = null;
        $borrower = null;
        $user = null;
        $employee = null;

        if ($this->entity_type === Partner::class) {
            $partner = $this->partner;
        } elseif ($this->entity_type === Borrower::class) {
            $borrower = $this->borrower;
            $partner = $borrower?->partner;
        } elseif ($this->entity_type === User::class) {
            // A seat billed to an admin/staff User rather than a Partner or Borrower.
            $user = $this->user;
        } elseif ($this->entity_type === Employee::class) {
            $employee = $this->employee;
        }

        // Amount mapping mirrors createInvoiceForSubscription() — this screen must
        // predict what the cron will actually charge, so the two have to agree.
        // ?: rather than ?? throughout, because a 0.00 column should fall through to
        // the next source rather than being reported as a real $0 charge.
        // BillingService::billingAmountSql() sorts on this same amount - change both.
        $billingAmount = match ($this->current_phase) {
            // The adjusted last installment when that is next (BUG-049).
            Subscription::PHASE_INSTALLMENT => $this->resource->nextInstallmentAmount() ?: 0,
            Subscription::PHASE_PLATFORM_FEE => $this->monthly_platform_fee ?: 0,
            Subscription::PHASE_USER_SEAT => $this->per_seat_amount ?: 0,
            // Nothing recurs after a borrower finishes enrollment.
            Subscription::PHASE_COMPLETED => 0,
            // Enrollment phase: the cron bills the down payment first when the plan
            // has one, and only then falls to the per-cycle/one-off amount.
            default => ($this->down_payment > 0 && !$this->installments_paid)
                ? $this->down_payment
                : ($this->subscription_amount_per_month ?: ($this->payable_amount ?: 0)),
        };

        $borrowerName = $borrower ? trim($borrower->first_name . ' ' . $borrower->last_name) : null;

        // Human readable billing type for UI table
        $billingType = match ($this->current_phase) {
            Subscription::PHASE_PLATFORM_FEE => 'Platform Fee',
            Subscription::PHASE_USER_SEAT => 'User Seat',
            Subscription::PHASE_INSTALLMENT => 'Installment',
            Subscription::PHASE_COMPLETED => 'Completed',
            default => ucfirst(str_replace('_', ' ', $this->current_phase ?? 'Installment')),
        };

        return [
            'id' => $this->id,
            'subscription_id' => $this->id,
            'partner_id' => $partner?->id,
            'borrower_id' => $borrower?->id,
            'partner_name' => $partner?->full_name,
            'company_name' => $partner?->company_name,
            'partner_email' => $partner?->email,
            'borrower_name' => $borrowerName,
            'borrower_email' => $borrower?->email,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'employee_id' => $employee?->id,
            'employee_name' => $employee?->full_name,
            'employee_email' => $employee?->email,
            // Generic name/email/type the UI can read without knowing all four
            // entity kinds — the per-type fields above are kept for anything that
            // already reads them directly.
            //
            // Keyed on entity_type, NOT a ?? chain over $partner/$borrower/etc:
            // $partner is populated for a Borrower row too (it holds the borrower's
            // OWN partner, e.g. the host, for is_billing_paused / display elsewhere),
            // so a ?? chain that checks $partner first would show the borrower's
            // partner's name instead of the borrower's own name on every borrower row.
            'subscriber_type' => class_basename($this->entity_type ?? ''),
            'subscriber_name' => match ($this->entity_type) {
                Partner::class => $partner?->full_name,
                Borrower::class => $borrowerName,
                User::class => $user?->name,
                Employee::class => $employee?->full_name,
                default => null,
            },
            'subscriber_email' => match ($this->entity_type) {
                Partner::class => $partner?->email,
                Borrower::class => $borrower?->email,
                User::class => $user?->email,
                Employee::class => $employee?->email,
                default => null,
            },
            // One cycle date now: it is both what the customer is paid through and
            // when the cron next bills them. NULL means nothing is scheduled -
            // either a borrower who has paid in full (current_phase 'completed') or
            // a subscriber whose first payment has not landed. There used to be a
            // separate paid_through_date; it would now carry the identical value, so
            // the UI was showing every row its own billing date twice.
            'upcoming_billing_date' => toDateOnly($this->subscription_end_date),
            'upcoming_billing_amount' => (float) $billingAmount,
            'upcoming_billing_type' => $billingType,
            // Billing is paused while the subscriber (or a borrower's partner) is
            // not Active. Surfaced so the list stops showing a confident next-charge
            // date for someone the cron will not actually bill.
            'is_billing_paused' => (bool) $this->is_billing_paused,
            'billing_paused_reason' => $this->billingPausedReason(),
            // Money already invoiced and still owed. On a paused row this is the
            // amount nothing will collect until someone acts on it.
            'outstanding_amount' => $this->owed(),
            'current_phase' => $this->current_phase,
            'enrollment_type' => $this->enrollment_type,
            'status' => $this->status ?? Subscription::STATUS_ACTIVE,
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d'),
            'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d'),
        ];
    }

    /** The open invoices BillingService counts as owed to the viewer on this row. */
    private function receivable()
    {
        return $this->relationLoaded('receivableInvoices') ? $this->receivableInvoices : collect();
    }

    private function owed(): float
    {
        return round((float) $this->receivable()->sum('payment_amount'), 2);
    }

    /**
     * The host's row for a borrower fee a partner still owes: billed to the
     * partner, for the client named, due since the fee was raised.
     */
    private function borrowerFeeRow(): array
    {
        $borrower = $this->borrower;
        $partner = $borrower?->partner;
        $fee = $this->receivable()->sortBy('created_at')->first();
        $reason = self::borrowerFeePausedReason($this->resource);

        return [
            'id' => $this->id,
            'subscription_id' => $this->id,
            'billing_row' => self::ROW_BORROWER_FEE,
            'partner_id' => $partner?->id,
            'borrower_id' => $borrower?->id,
            'partner_name' => $partner?->full_name,
            'company_name' => $partner?->company_name,
            'partner_email' => $partner?->email,
            'subscriber_type' => class_basename(Partner::class),
            'subscriber_name' => $partner?->full_name,
            'subscriber_email' => $partner?->email,
            'for_client_name' => $borrower ? trim($borrower->first_name . ' ' . $borrower->last_name) : null,
            'fee_invoice_id' => $fee?->id,
            'fee_invoice_number' => $fee?->invoice_id,
            'upcoming_billing_date' => $fee ? toTimezone($fee->created_at, null, 'Y-m-d') : null,
            'upcoming_billing_amount' => $this->owed(),
            'upcoming_billing_type' => Invoice::typeLabel(Invoice::INVOICE_TYPE_BORROWER_FEE),
            'is_billing_paused' => $reason !== null,
            'billing_paused_reason' => $reason,
            'outstanding_amount' => $this->owed(),
            'current_phase' => self::ROW_BORROWER_FEE,
            'enrollment_type' => null,
            'status' => $fee?->status ?? Invoice::STATUS_UNPAID,
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d'),
            'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d'),
        ];
    }
}
