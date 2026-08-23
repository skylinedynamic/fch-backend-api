<?php

namespace App\Services\FCH\Subscription;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\ActivityLog\ActivityLog;
use App\Services\FCH\Common\Invoice\InvoiceService;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Mail\Borrower\BorrowerMigrationMail;
use App\Mail\Borrower\BorrowerPaymentLinkMail;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Mail\Partner\PartnerPaymentLinkMail;
use App\Mail\Partner\PartnerMigrationMail;
use App\Mail\Invoice\InvoiceMail;

/**
 * EnrollmentService
 *
 * Single authoritative service for all enrollment billing & mailing logic:
 *  - Plan resolution (Partner + Borrower)
 *  - Amount calculation (one-time, installment)
 *  - Subscription creation
 *  - Enrollment invoice creation & activity logging
 *  - Enrollment email notifications & payment links
 *
 * BorrowerService and PartnerService are thin orchestrators that delegate
 * all enrollment, invoicing, and enrollment mailing tasks to this service.
 */
class EnrollmentService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected SubscriptionService $subscriptionService,
        protected ActivityLogService $activityLogService,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MAIL HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    private function sendBorrowerMail(string $email, object $mailable, int|null $partnerId): void
    {
        try {
            Mail::to($email)->send($mailable);
        } catch (\Exception $e) {
            Log::error('Borrower enrollment email failed', [
                'email' => $email,
                'partner_id' => $partnerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendPartnerMail(string $email, object $mailable, int $senderPartnerId, int $partnerId): void
    {
        try {
            Mail::to($email)->send($mailable);
        } catch (\Exception $e) {
            Log::error('Partner enrollment email failed', [
                'partner_id' => $partnerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PLAN RESOLUTION
    // ──────────────────────────────────────────────────────────────────────────

    public function resolvePartnerPlan(?int $planId): ?SubscriptionPlan
    {
        return SubscriptionPlan::find($planId);
    }

    public function resolveBorrowerPlan(?int $planId): array
    {
        $plan = SubscriptionPlan::where('id', $planId)
            ->where('entity_type', Borrower::class)
            ->first();

        return [$plan, $planId];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AMOUNT CALCULATION
    // ──────────────────────────────────────────────────────────────────────────

    public function calculatePartnerAmounts(
        ?SubscriptionPlan $plan,
        array $enrollment,
        ?Subscription $existing = null
    ): array {
        $enrollmentType = $enrollment['enrollment_type']
            ?? $existing?->enrollment_type
            ?? $plan?->enrollment_type
            ?? '';

        $isInstallment = strtolower((string) $enrollmentType) === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG;

        $totalAmount = isset($enrollment['amount']) && $enrollment['amount'] !== ''
            ? (float) $enrollment['amount']
            : ($existing
                ? (float) $existing->payable_amount
                : (float) ($plan->total_amount ?? 0));

        $monthly = isset($enrollment['monthly_platform_fee']) && $enrollment['monthly_platform_fee'] !== ''
            ? (float) $enrollment['monthly_platform_fee']
            : ($existing
                ? (float) $existing->monthly_platform_fee
                : (float) ($plan?->platform_fee ?? 0));

        $downPayment = 0.0;
        $installmentMonths = (int) ($enrollment['months'] ?? $plan?->total_installment_count_upto ?? 1);
        $monthlyInstallmentAmount = 0.0;
        $remainingBalance = 0.0;

        if ($isInstallment) {
            $downPayment = isset($enrollment['down_payment']) && $enrollment['down_payment'] !== ''
                ? (float) $enrollment['down_payment']
                : 0.0;

            $monthlyInstallmentAmount = isset($enrollment['monthly_amount']) && $enrollment['monthly_amount'] !== ''
                ? (float) $enrollment['monthly_amount']
                : 0.0;

            $remainingBalance = isset($enrollment['remaining_balance']) && $enrollment['remaining_balance'] !== ''
                ? (float) $enrollment['remaining_balance']
                : max(0.0, $totalAmount - $downPayment);

            if ($monthlyInstallmentAmount <= 0 && $installmentMonths > 0) {
                $monthlyInstallmentAmount = $remainingBalance > 0
                    ? ($remainingBalance / $installmentMonths)
                    : 0.0;
            }

            $payableAmount = $totalAmount;
            $total = $remainingBalance;
            $initialInvoiceAmount = $downPayment > 0 ? $downPayment : $monthlyInstallmentAmount;
            $installmentType = 1;
            $subscriptionAmountPerMonth = $monthlyInstallmentAmount;
        } else {
            $payableAmount = $totalAmount;
            $total = $totalAmount;
            $initialInvoiceAmount = $totalAmount;
            $installmentType = 0;
            $subscriptionAmountPerMonth = 0;
        }

        $isCustom = false;
        if ($plan) {
            $isCustom = abs($totalAmount - (float) ($plan->total_amount ?? $plan->payment_amount ?? 0)) > 0.01
                || abs($monthly - (float) ($plan->platform_fee ?? 0)) > 0.01;
        }

        return [
            'enrollment' => $totalAmount,
            'monthly' => $monthly,
            'total' => $total,
            'payable_amount' => $payableAmount,
            'initial_invoice_amount' => $initialInvoiceAmount,
            'down_payment' => $downPayment,
            'installment_months' => $installmentMonths,
            'installment_monthly_amount' => $monthlyInstallmentAmount,
            'installment_remaining_balance' => $remainingBalance,
            'installment_type' => $installmentType,
            'subscription_amount_per_month' => $subscriptionAmountPerMonth,
            'is_custom' => $isCustom,
        ];
    }

    public function calculateBorrowerAmounts(
        ?SubscriptionPlan $plan,
        array $enrollment,
        float $fallbackAmount = 2497.00
    ): array {
        $default = $plan ? (float) ($plan->payment_amount ?? $fallbackAmount) : $fallbackAmount;

        $amount = isset($enrollment['amount']) && $enrollment['amount'] !== ''
            ? (float) $enrollment['amount']
            : $default;

        $isInstallment = $plan && $plan->isInstallmentPlan();

        $downPayment = 0.0;
        $installmentMonths = $plan ? (int) ($plan->total_installment_count_upto ?? 12) : 12;
        $monthlyInstallmentAmount = 0.0;
        $remainingBalance = 0.0;

        if ($isInstallment) {
            $downPayment = isset($enrollment['down_payment']) && $enrollment['down_payment'] !== ''
                ? (float) $enrollment['down_payment']
                : 0.0;

            if (isset($enrollment['months']) && $enrollment['months'] !== '') {
                $installmentMonths = (int) $enrollment['months'];
            }

            $monthlyInstallmentAmount = isset($enrollment['monthly_amount']) && $enrollment['monthly_amount'] !== ''
                ? (float) $enrollment['monthly_amount']
                : 0.0;

            $remainingBalance = isset($enrollment['remaining_balance']) && $enrollment['remaining_balance'] !== ''
                ? (float) $enrollment['remaining_balance']
                : max(0.0, $amount - $downPayment);

            if ($monthlyInstallmentAmount <= 0 && $installmentMonths > 0) {
                $monthlyInstallmentAmount = $remainingBalance > 0
                    ? ($remainingBalance / $installmentMonths)
                    : 0.0;
            }

            $payableAmount = $amount;
            $total = $remainingBalance;
            $initialInvoiceAmount = $downPayment > 0 ? $downPayment : $monthlyInstallmentAmount;
            $installmentType = 1;
            $subscriptionAmountPerMonth = $monthlyInstallmentAmount;
        } else {
            $downPayment = 0.0;
            $payableAmount = $amount;
            $total = $plan ? (float) ($plan->total_amount ?? $amount) : $amount;
            if (isset($enrollment['amount']) && $enrollment['amount'] !== '') {
                $total = $amount;
            }
            $initialInvoiceAmount = $amount;
            $installmentType = 0;
            $installmentMonths = $plan ? (int) ($plan->total_installment_count_upto ?? 1) : 1;
            $subscriptionAmountPerMonth = $payableAmount;
        }

        $isCustom = false;
        if ($plan) {
            $isCustom = abs($amount - (float) ($plan->payment_amount ?? 0)) > 0.01
                || abs($total - (float) ($plan->total_amount ?? 0)) > 0.01;
        }

        return [
            'initial_invoice_amount' => $initialInvoiceAmount,
            'total' => $total,
            'payable_amount' => $payableAmount,
            'down_payment' => $downPayment,
            'installment_months' => $installmentMonths,
            'installment_type' => $installmentType,
            'subscription_amount_per_month' => $subscriptionAmountPerMonth,
            'is_custom' => $isCustom,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PARTNER ENROLLMENT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Run the full enrollment billing & mail flow for a partner.
     *
     * Creates subscription, creates enrollment invoice, logs activity, and dispatches email.
     */
    public function enrollPartner(
        Partner $partner,
        object $user,
        array $enrollment,
        ?string $plainPassword = null,
        bool $isMigration = false
    ): array {
        $planId = isset($enrollment['plan_id']) ? (int) $enrollment['plan_id'] : null;
        $enrollmentType = $enrollment['enrollment_type'] ?? null;
        $partnerType = $enrollment['partner_type'] ?? $partner->partner_type;

        $plan = $this->resolvePartnerPlan($planId);
        $amounts = $this->calculatePartnerAmounts($plan, $enrollment);

        $subscription = null;

        if ($enrollmentType || $amounts['initial_invoice_amount'] > 0) {
            $subscription = $this->subscriptionService->startSubscription($partner, $plan, [
                'subscription_plan_id' => $plan?->id,
                'partner_type' => $partnerType,
                'enrollment_type' => $enrollmentType,
                'installment_type' => $amounts['installment_type'],
                'installment_count' => $amounts['installment_months'],
                'monthly_platform_fee' => $amounts['monthly'],
                'payable_amount' => $amounts['payable_amount'],
                'total_subscription_amount' => $amounts['total'],
                'subscription_amount_per_month' => $amounts['subscription_amount_per_month'],
                'down_payment' => $amounts['down_payment'],
                'is_custom' => $amounts['is_custom'],
            ]);
        }

        $invoice = null;

        if ($amounts['initial_invoice_amount'] > 0) {
            $invoice = $this->invoiceService->create([
                'partner_id' => $partner->id,
                'entity_type' => Partner::class,
                'entity_id' => $partner->id,
                'subscription_id' => $subscription?->id,
                'customer_name' => $partner->full_name,
                'email' => $partner->email,
                'country' => $partner->country,
                'month_count' => 1,
                'payment_amount' => $amounts['initial_invoice_amount'],
                'is_partner_paying' => false,
                'status' => Invoice::STATUS_UNPAID,
                'type' => ($subscription->enrollment_type == SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT && $subscription->down_payment > 0) ? Invoice::INVOICE_TYPE_DOWN_PAYMENT : Invoice::INVOICE_TYPE_ENROLLMENT,
            ]);

            $subscription->update(['current_cycle_invoice_id' => $invoice->id]);

            $this->activityLogService->logModelEvent($invoice, ActivityLog::LOG_TYPE_INVOICE_CREATE);

            $authUser = Auth::user();
            $senderPartnerId = $authUser ? $authUser->partner_id : Partner::where('is_host', 1)->value('id');
            $paymentLink = concatUrl(getPartnerDomain($partner->id), 'invoices', config('app.frontend_partner_url'));

            if ($isMigration) {
                $hostPartner = Partner::where('is_host', 1)->first();
                $mailData = [
                    'partner_id' => $partner->id,
                    'user' => $user,
                    'payment_link' => $paymentLink,
                    'amount' => $invoice->payment_amount,
                    'enrollment_type' => $enrollmentType ?? 'Enrollment Fee',
                    'business_info' => [
                        'company_name' => $hostPartner->company_name ?? config('app.name'),
                        'email' => $hostPartner->email ?? config('mail.from.address'),
                        'phone_number' => $hostPartner->phone_number ?? config('app.phone_number', ''),
                        'address_line_1' => $hostPartner->address_line_1 ?? config('app.address', ''),
                        'company_logo' => $hostPartner->company_logo ?? 'logo.png',
                    ],
                ];
                $this->sendPartnerMail($user->email, new PartnerMigrationMail($mailData, $senderPartnerId), $senderPartnerId, $partner->id);
            } else {
                $mailData = [
                    'partner_id' => $partner->id,
                    'user' => $user,
                    'payment_link' => $paymentLink,
                    'amount' => $invoice->payment_amount,
                    'enrollment_type' => $enrollmentType ?? '',
                    'business_info' => [
                        'company_name' => config('app.name'),
                        'email' => config('mail.from.address'),
                        'phone_number' => config('app.phone_number', ''),
                        'address_line_1' => config('app.address', ''),
                        'company_logo' => 'logo.png',
                    ],
                    'plain_password' => $plainPassword,
                ];
                $this->sendPartnerMail($user->email, new PartnerPaymentLinkMail($mailData, $senderPartnerId), $senderPartnerId, $partner->id);
            }
        }

        return [
            'subscription' => $subscription,
            'invoice' => $invoice,
            'amounts' => $amounts,
            'plan' => $plan,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // BORROWER ENROLLMENT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Run the full enrollment billing & mail flow for a borrower.
     *
     * Creates subscription, creates enrollment invoice(s), logs activity, and dispatches email.
     */
    public function enrollBorrower(
        Borrower $borrower,
        Partner $partner,
        object $user,
        array $enrollment,
        bool $isHostCreating,
        bool $isMigration = false,
        ?string $plainPassword = null
    ): array {
        $isNonHostPartner = !$isHostCreating && ($partner ? !$partner->is_host : true);
        $planId = isset($enrollment['plan_id']) ? (int) $enrollment['plan_id'] : null;

        [$plan] = $this->resolveBorrowerPlan($planId);

        $partnerInvoice = null;
        $wlPartnerFeeAmount = null;

        if ($isNonHostPartner) {
            // Non-host partner creates borrower: partner pays a fixed borrower fee to Host
            $borrower->update(['partner_payment_for_me' => false]);

            $wlFeePlan = SubscriptionPlan::where('enrollment_type_slug', SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG)
                ->where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE)
                ->first();

            $wlPartnerFeeAmount = $wlFeePlan ? (float) ($wlFeePlan->total_amount ?? 0) : 0.00;

            $partnerInvoice = $this->invoiceService->create([
                'partner_id' => $borrower->partner_id,
                'borrower_id' => $borrower->id,
                'entity_type' => Partner::class,
                'entity_id' => $borrower->partner_id,
                'customer_name' => $partner->company_name ?: $partner->full_name,
                'email' => $partner->email,
                'country' => $partner->country,
                'payment_amount' => $wlPartnerFeeAmount,
                'status' => Invoice::STATUS_UNPAID,
                'is_partner_paying' => true,
                'is_platform_fee' => false,
                'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            ]);

            $this->activityLogService->logModelEvent($partnerInvoice, ActivityLog::LOG_TYPE_INVOICE_CREATE);

            $amounts = $this->calculateBorrowerAmounts($plan, $enrollment);
        } else {
            // Host partner path
            $fallback = 0.0;
            $amounts = $this->calculateBorrowerAmounts($plan, $enrollment, $fallback);
        }

        // Borrower enrollment invoice
        $invoice = $this->invoiceService->create([
            'partner_id' => $borrower->partner_id,
            'borrower_id' => $borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'customer_name' => $borrower->full_name,
            'email' => $borrower->email,
            'country' => $borrower->country,
            'payment_amount' => $amounts['initial_invoice_amount'],
            'status' => Invoice::STATUS_UNPAID,
            'is_partner_paying' => $isNonHostPartner ? false : ($isHostCreating ? false : true),
            'is_platform_fee' => false,
            'type' => ($amounts['installment_type'] && isset($amounts['down_payment']) && $amounts['down_payment'] > 0) ? Invoice::INVOICE_TYPE_DOWN_PAYMENT : Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);

        $this->activityLogService->logModelEvent($invoice, ActivityLog::LOG_TYPE_INVOICE_CREATE);

        // For non-host partner borrower creation: send notification mail to Host Admin for the borrower invoice
        if ($isNonHostPartner) {
            $hostPartner = Partner::where('is_host', true)->first();
            $hostEmail = $hostPartner?->email ?: $hostPartner?->contact_person_email ?: config('mail.from.address');
            if ($hostEmail) {
                try {
                    $hostPartnerId = $hostPartner?->id ?: 1;
                    $subject = 'New Borrower Invoice #' . ($invoice->invoice_id ?: $invoice->id) . ' - ' . ($borrower->full_name ?: 'Borrower');
                    $this->sendPartnerMail(
                        $hostEmail,
                        new InvoiceMail($invoice, null, $hostPartnerId, $subject),
                        $hostPartnerId,
                        $hostPartnerId
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to send borrower invoice notification mail to host admin', ['error' => $e->getMessage()]);
                }
            }
        }

        // Create / update subscription
        $subscription = $this->subscriptionService->startSubscription($borrower, $plan, [
            'monthly_platform_fee' => 0,
            'total_subscription_amount' => $amounts['total'],
            'payable_amount' => $amounts['payable_amount'],
            'down_payment' => $amounts['down_payment'],
            'installment_count' => $amounts['installment_months'],
            'installment_type' => $amounts['installment_type'],
            'subscription_amount_per_month' => $amounts['subscription_amount_per_month'],
            'is_custom' => $amounts['is_custom'],
            'borrower_fee_amount' => $wlPartnerFeeAmount,
            'current_cycle_invoice_id' => $invoice->id,
        ]);

        // Link invoices → subscription
        $invoice->update(['subscription_id' => $subscription->id]);
        if ($partnerInvoice) {
            $partnerInvoice->update(['subscription_id' => $subscription->id]);
        }

        // Send enrollment mail
        $mailData = [
            'user' => $user,
            'plain_password' => $plainPassword,
            'business_info' => [
                'company_name' => $partner->company_name ?? 'Four Corner Funding',
                'company_logo' => $partner->company_logo ?? 'logo.png',
                'email' => $partner->email ?? config('mail.from.address'),
                'phone_number' => $partner->phone_number ?? '',
                'address_line_1' => $partner->address_line_1 ?? '',
            ],
        ];

        if ($isMigration) {
            if ($amounts['initial_invoice_amount'] > 0) {
                $mailData['payment_link'] = concatUrl(getBorrowerDomain($invoice->partner_id), 'invoices', config('app.frontend_borrower_url'));
                $mailData['amount'] = $invoice->payment_amount;
                $this->sendBorrowerMail($user->email, new BorrowerMigrationMail($mailData, $borrower->partner_id), $borrower->partner_id);
            }
        } elseif ($isHostCreating) {
            if ($amounts['initial_invoice_amount'] > 0) {
                $mailData['payment_link'] = concatUrl(getBorrowerDomain($invoice->partner_id), 'invoices', config('app.frontend_borrower_url'));
                $mailData['amount'] = $invoice->payment_amount;
                $this->sendBorrowerMail($user->email, new BorrowerPaymentLinkMail($mailData, $borrower->partner_id), $borrower->partner_id);
            } else {
                $this->sendBorrowerMail($user->email, new BorrowerAccountCreatedMail($mailData, $borrower->partner_id), $borrower->partner_id);
            }
        } else {
            $this->sendBorrowerMail($user->email, new BorrowerAccountCreatedMail($mailData, $borrower->partner_id), $borrower->partner_id);
        }

        return [
            'subscription' => $subscription,
            'invoice' => $invoice,
            'partner_invoice' => $partnerInvoice,
            'amounts' => $amounts,
            'plan' => $plan,
        ];
    }
}
