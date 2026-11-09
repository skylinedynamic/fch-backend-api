<?php

namespace Tests\Feature\Billing;

use App\Mail\Invoice\InvoiceMail;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Services\FCH\Common\Invoice\InvoiceService;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-049: the billing cron bills the last installment at the adjusted amount
 * (balance - monthly x (months - 1)), so the installments total the plan
 * exactly, and the invoice, its email and the bell say why it differs.
 *
 * 6696.70 over 12 months: 558.06 a month; the 12th is 558.04 (2 cents less).
 */
class FinalInstallmentBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function borrowerOnInstallments(array $attributes = []): Subscription
    {
        $partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Reseller Partner LLC',
            'full_name' => 'Reseller Owner',
            'email' => 'reseller@example.test',
        ]);

        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Final',
            'last_name' => 'Payer',
            'email' => 'final.payer@example.test',
        ]);

        $plan = SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG,
            'entity_type' => Borrower::class,
            'total_amount' => 7696.70,
            'total_installment_count_upto' => 12,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1,
            'status' => true,
        ]);

        $subscription = Subscription::create(array_merge([
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'subscription_plan_id' => $plan->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 11,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.70,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
            'monthly_platform_fee' => 0,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => today()->copy()->subMonths(11),
            'subscription_end_date' => today()->copy()->subDay(),
        ], $attributes));

        // The down payment, long since paid: this is not the first cycle.
        Invoice::create([
            'invoice_id' => 'INV-000001',
            'subscription_id' => $subscription->id,
            'partner_id' => $partner->id,
            'borrower_id' => $borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'payment_amount' => 1000.00,
            'month_count' => 1,
            'status' => Invoice::STATUS_PAID,
            'type' => Invoice::INVOICE_TYPE_DOWN_PAYMENT,
            'currency' => 'USD',
        ]);

        return $subscription;
    }

    private function lastRaised(Subscription $subscription): ?Invoice
    {
        return Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_INSTALLMENT)
            ->latest('id')
            ->first();
    }

    #[Test]
    public function the_last_installment_is_billed_at_the_adjusted_amount(): void
    {
        $subscription = $this->borrowerOnInstallments();

        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $invoice = $this->lastRaised($subscription);
        $this->assertNotNull($invoice, 'the last cycle should have been billed');
        $this->assertEquals(558.04, (float) $invoice->payment_amount);
    }

    #[Test]
    public function the_invoice_says_it_is_the_last_installment_and_why_it_differs(): void
    {
        $subscription = $this->borrowerOnInstallments();

        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $details = $this->lastRaised($subscription)->details;

        $this->assertSame([
            'description' => __('invoice.final_installment.description', ['number' => 12, 'count' => 12]),
            'detail' => 'This last payment is $0.02 less than your usual $558.06, so your 12 payments add up to exactly $6,696.70.',
            'amount' => 558.04,
        ], $details['items'][0]);
        $this->assertSame(-0.02, $details['final_installment']['adjustment']);
        $this->assertStringContainsString('Installment 12 of 12', $details['items'][0]['description']);
    }

    #[Test]
    public function a_last_installment_that_is_higher_says_more(): void
    {
        // 1000 over 3: 333.33 a month, the last 333.34.
        $subscription = $this->borrowerOnInstallments([
            'installment_count' => 3,
            'installments_paid' => 2,
            'total_subscription_amount' => 1000.00,
            'subscription_amount_per_month' => 333.33,
        ]);

        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $invoice = $this->lastRaised($subscription);
        $this->assertEquals(333.34, (float) $invoice->payment_amount);
        $this->assertSame(
            'This last payment is $0.01 more than your usual $333.33, so your 3 payments add up to exactly $1,000.00.',
            $invoice->details['items'][0]['detail']
        );
    }

    #[Test]
    public function an_installment_before_the_last_is_billed_and_described_as_before(): void
    {
        $subscription = $this->borrowerOnInstallments(['installments_paid' => 5]);

        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $invoice = $this->lastRaised($subscription);
        $this->assertEquals(558.06, (float) $invoice->payment_amount);
        $this->assertNull($invoice->details);
    }

    #[Test]
    public function an_even_split_bills_the_last_installment_like_the_others(): void
    {
        $subscription = $this->borrowerOnInstallments([
            'total_subscription_amount' => 6696.00,
            'subscription_amount_per_month' => 558.00,
        ]);

        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $invoice = $this->lastRaised($subscription);
        $this->assertEquals(558.00, (float) $invoice->payment_amount);
        $this->assertNull($invoice->details, 'nothing to explain');
    }

    #[Test]
    public function the_bell_says_it_is_the_final_installment(): void
    {
        $notifier = $this->spy(Notifier::class);
        $subscription = $this->borrowerOnInstallments();

        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $invoice = $this->lastRaised($subscription);
        $notifier->shouldHaveReceived('send')->withArgs(fn (string $key, $to = null, array $data = []) => $key === 'invoice.final_installment'
            && $data['id'] === $invoice->id
            && $data['amount'] === 'USD 558.04'
            && $data['difference'] === '$0.02 less'
            && $data['monthly'] === '$558.06'
            && $data['total'] === '$6,696.70');
        $notifier->shouldNotHaveReceived('send', ['invoice.sent', \Mockery::any(), \Mockery::any(), \Mockery::any()]);
    }

    #[Test]
    public function the_invoice_email_explains_the_final_installment(): void
    {
        $subscription = $this->borrowerOnInstallments();
        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $html = (new InvoiceMail($this->lastRaised($subscription)))->render();

        $this->assertStringContainsString(
            e('This last payment is $0.02 less than your usual $558.06, so your 12 payments add up to exactly $6,696.70.'),
            $html
        );
    }

    #[Test]
    public function the_invoice_pdf_names_the_final_installment_and_the_adjustment(): void
    {
        $subscription = $this->borrowerOnInstallments();
        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $invoice = $this->lastRaised($subscription);
        $service = app(InvoiceService::class);
        $businessInfo = (new \ReflectionMethod($service, 'getBusinessInfo'))->invoke($service, $invoice);

        $html = view('invoices.download', ['invoice' => $invoice, 'business_info' => $businessInfo])->render();

        $this->assertStringContainsString('Installment 12 of 12 (final)', $html);
        $this->assertStringContainsString(e($invoice->details['items'][0]['detail']), $html);
        $this->assertStringContainsString('$558.04', $html);
    }

    #[Test]
    public function an_ordinary_invoice_email_has_no_such_note(): void
    {
        $subscription = $this->borrowerOnInstallments(['installments_paid' => 5]);
        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $html = (new InvoiceMail($this->lastRaised($subscription)))->render();

        $this->assertStringNotContainsString('final installment', strtolower($html));
    }
}
