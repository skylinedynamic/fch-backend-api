<?php

namespace App\Services\FCH\Common\Commission;

use App\Mail\Commission\CommissionPayoutMail;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Commission\CommissionPayout;
use App\Models\Compensation\CompensationSetting;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Compensation\CompensationService;
use App\Services\FCH\Common\Invoice\InvoiceService;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Who a commission is paid out to, how much, and paying it.
 *
 * Whose client it is decides the payee, and both work the same way:
 *
 *  - a client of the host partner is paid to the user assigned to that client,
 *    out of the host's share of the deal — the admin commission. Their Funding
 *    compensation decides the amount: a Commission is a percentage of the admin
 *    commission, a Flat Amount is paid as it is. A rate set for "All" services
 *    covers Funding when there's no Funding-specific one.
 *  - a client of any other partner is paid to that partner: its share of the
 *    total commission.
 *
 * Until it's paid the amount is worked out live; paying records a commission
 * payout, an invoice and a payment, and marks the commission Paid and
 * Completed. From then on the amount shown is what the invoice says.
 */
class CommissionPayoutService
{
    public const SERVICE_FUNDING = 'Funding';

    public const BASE_ADMIN_COMMISSION = 'Admin Commission';
    public const BASE_TOTAL_COMMISSION = 'Total Commission';

    public function __construct(
        private readonly CompensationService $compensation,
        private readonly InvoiceService $invoices,
    ) {
    }

    /** The payout for the commission page — null when the commission has no client to pay out on. */
    public function forCommission(Commission $commission): ?array
    {
        if (!$commission->borrower) {
            return null;
        }

        $paid = $commission->payout;

        if ($paid) {
            return [
                'payee' => [
                    'type' => $paid->payeeType(),
                    'id' => $paid->entity_id,
                    'name' => $paid->name,
                    'email' => $paid->email,
                ],
                'paid_by' => null,
                'service' => null,
                'payout_type' => $paid->payout_type,
                'rate' => null,
                'base_label' => null,
                'base_amount' => null,
                'amount' => $paid->invoice ? round((float) $paid->invoice->payment_amount, 2) : null,
                'is_paid' => true,
                // The viewer's day (X-Timezone), not the UTC one (BUG-120).
                'paid_at' => toTimezone($paid->paid_at, null, __('commission.date_format')),
                'payment_method' => $paid->payment?->payment_type,
                'can_pay' => false,
            ];
        }

        $payout = $this->calculate($commission);

        // Paid by the older flow, before payouts were recorded: it left an
        // invoice and a payment but no payout record.
        if ($commission->payment_status === Commission::PAYMENT_STATUS_PAID) {
            return array_merge($payout, [
                'is_paid' => true,
                'paid_at' => toDateOnly($commission->payout_date, __('commission.date_format')),
                'payment_method' => $this->legacyPayment($commission)?->payment_type,
                'can_pay' => false,
            ]);
        }

        return array_merge($payout, [
            'is_paid' => false,
            'paid_at' => null,
            'payment_method' => null,
            'can_pay' => $this->reasonNotPayable($commission, $payout) === null,
            // What the payout dialog's Environment starts on.
            'default_environment' => PaymentProvider::defaultModeForApp(),
        ]);
    }

    /**
     * Pay the payee: record the payout, an invoice and a payment, and mark the
     * commission Paid and Completed.
     *
     * @param string $environment PaymentProvider::MODE_*, as the host stated it
     * @throws ValidationException when there's nothing that can be paid yet
     */
    public function pay(Commission $commission, string $paymentMethod, string $environment, ?string $note = null): Commission
    {
        [$commission, $invoice] = DB::transaction(function () use ($commission, $paymentMethod, $environment, $note) {
            // Locked, so two clicks can't both pay.
            $commission = Commission::whereKey($commission->getKey())->lockForUpdate()->firstOrFail();

            $payout = $this->calculate($commission);

            if ($reason = $this->reasonNotPayable($commission, $payout)) {
                throw ValidationException::withMessages(['payout' => $reason]);
            }

            $payee = $payout['payee'];
            $entityType = CommissionPayout::PAYEE_TYPES[$payee['type']];
            $partner = $payee['type'] === 'partner' ? Partner::find($payee['id']) : null;

            $invoice = Invoice::create([
                'invoice_id' => $this->invoices->generateInvoiceId(),
                'partner_id' => $commission->partner_id,
                'borrower_id' => $commission->borrower_id,
                'entity_type' => $entityType,
                'entity_id' => $payee['id'],
                // The person paid, never the company: a partner payee's name is
                // its company, so a partner is named by its owner only.
                'customer_name' => $partner ? $partner->full_name : $payee['name'],
                'email' => $payee['email'],
                'country' => $partner?->country,
                'payment_amount' => $payout['amount'],
                // payment_date is a DATE column - store the calendar date.
                'payment_date' => today(),
                'status' => Invoice::STATUS_PAID,
                'type' => Invoice::INVOICE_TYPE_COMMISSION_PAYOUT,
                'currency' => $commission->currency,
                'currency_symbol' => $commission->currency_symbol,
                'conversion_rate' => $commission->conversion_rate,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            $payment = (new Payment())->createRecord([
                'partner_id' => $commission->partner_id,
                'borrower_id' => $commission->borrower_id,
                'invoice_id' => $invoice->id,
                'entity_type' => $entityType,
                'entity_id' => $payee['id'],
                'amount' => $payout['amount'],
                'currency' => $commission->currency,
                'currency_symbol' => $commission->currency_symbol,
                'conversion_rate' => $commission->conversion_rate,
                'payment_from' => $paymentMethod,
                'payment_type' => $paymentMethod,
                'status' => Payment::STATUS_SUCCEEDED,
                'is_environment' => Payment::environmentFromMode($environment),
                'transaction_date' => now(),
                'created_by' => Auth::id(),
            ]);

            CommissionPayout::create([
                'commission_id' => $commission->id,
                'entity_type' => $entityType,
                'entity_id' => $payee['id'],
                'name' => $payee['name'],
                'email' => $payee['email'],
                'payout_type' => $payout['payout_type'],
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'currency' => $commission->currency,
                'currency_symbol' => $commission->currency_symbol,
                'conversion_rate' => $commission->conversion_rate,
                'paid_at' => now(),
                'created_by' => Auth::id(),
            ]);

            $commission->update([
                'payment_status' => Commission::PAYMENT_STATUS_PAID,
                'commission_status' => Commission::STATUS_COMPLETED,
                // The day in the payer's zone, not the UTC one (BUG-120).
                'payout_date' => todayInRequestTimezone(),
            ]);

            return [$commission, $invoice];
        });

        // Whoever was paid — the partner or the assigned user — is told about
        // it, in the bell and by email. Once it's committed, so a notification
        // problem can't undo a payment made.
        try {
            $payee = $invoice->entity_type === Partner::class
                ? Partner::find($invoice->entity_id)
                : User::find($invoice->entity_id);

            app(Notifier::class)->send('partner.commission.paid', $payee, [
                'id' => $commission->id,
                'amount' => ($commission->currency ?: 'USD') . ' ' . number_format((float) $invoice->payment_amount, 2),
            ], mail: $invoice->email
                ? (new CommissionPayoutMail($commission, $invoice, $note))->to($invoice->email)
                : null);
        } catch (Throwable $e) {
            Log::error('Commission payout notification failed', ['commission_id' => $commission->id, 'error' => $e->getMessage()]);
        }

        return $commission;
    }

    /** The payment the older flow made for a paid commission's partner, found through its invoice. */
    private function legacyPayment(Commission $commission): ?Payment
    {
        $invoice = Invoice::where('type', Invoice::INVOICE_TYPE_COMMISSION_PAYOUT)
            ->where('partner_id', $commission->partner_id)
            ->where('borrower_id', $commission->borrower_id)
            ->latest('id')
            ->first();

        return $invoice ? Payment::where('invoice_id', $invoice->id)->latest('id')->first() : null;
    }

    /** Why this can't be paid now, or null when it can. */
    private function reasonNotPayable(Commission $commission, array $payout): ?string
    {
        if ($commission->payout()->exists() || $commission->payment_status === Commission::PAYMENT_STATUS_PAID) {
            return __('commission.payout_already_paid');
        }

        if (!$payout['payee'] || !$payout['amount'] || $payout['amount'] <= 0) {
            return __('commission.payout_nothing_to_pay');
        }

        // The deal has to be closed first.
        if ($commission->fundingApplication?->status !== FundingApplication::CLOSED_COMPLETED) {
            return __('commission.payout_funding_not_closed');
        }

        return null;
    }

    /** The payout worked out from the current commission and compensation. */
    private function calculate(Commission $commission): array
    {
        return $commission->borrower?->partner?->is_host
            ? $this->forAssignedUser($commission)
            : $this->forPartner($commission);
    }

    /** A client of another partner: that partner's share of the total commission. */
    private function forPartner(Commission $commission): array
    {
        $partner = $commission->partner;
        $amount = (float) $commission->partner_commission_amount;

        return [
            'payee' => $partner ? [
                'type' => 'partner',
                'id' => $partner->id,
                'name' => $partner->company_name ?: $partner->full_name,
                'email' => $partner->email,
            ] : null,
            'paid_by' => null,
            'service' => null,
            'payout_type' => CompensationSetting::PAYOUT_TYPE_COMMISSION,
            'rate' => round((float) $commission->partner_commission, 2),
            'base_label' => self::BASE_TOTAL_COMMISSION,
            'base_amount' => round((float) $commission->total_commission_amount, 2),
            'amount' => $partner && $amount > 0 ? round($amount, 2) : null,
        ];
    }

    /** A client of the host partner: the assigned user's Funding compensation, from the admin commission. */
    private function forAssignedUser(Commission $commission): array
    {
        $assignee = $commission->borrower?->assignedUser;
        $baseAmount = (float) $commission->admin_commission_amount;

        $payout = [
            'payee' => $assignee ? [
                'type' => 'user',
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
            ] : null,
            'paid_by' => null,
            'service' => null,
            'payout_type' => null,
            'rate' => null,
            'base_label' => self::BASE_ADMIN_COMMISSION,
            'base_amount' => round($baseAmount, 2),
            'amount' => null,
        ];

        if (!$assignee) {
            return $payout;
        }

        $rows = $this->compensation->getFor($assignee);
        $payout['paid_by'] = $rows->first()?->paid_by;

        if ($payout['paid_by'] !== CompensationSetting::PAID_BY_PER_DEAL) {
            return $payout;
        }

        $row = $rows->firstWhere('service', self::SERVICE_FUNDING)
            ?? $rows->firstWhere('service', CompensationSetting::SERVICE_ALL);

        if (!$row) {
            return $payout;
        }

        $rate = (float) $row->amount;

        return array_merge($payout, [
            'service' => $row->service,
            'payout_type' => $row->payout_type,
            'rate' => round($rate, 2),
            'amount' => $row->payout_type === CompensationSetting::PAYOUT_TYPE_COMMISSION
                ? round($baseAmount * $rate / 100, 2)
                : round($rate, 2),
        ]);
    }
}
