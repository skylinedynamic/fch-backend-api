<?php

namespace Tests\Feature\Subscription;

use App\Mail\Subscription\SubscriptionReactivatedMail;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Subscription\SubscriptionResumeService;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-069: a cancelled subscription can be resumed, on the plan it was on.
 *
 *  - before its end date: active again at once;
 *  - after it, with an invoice still open: that invoice is paid first;
 *  - after it, with none: the next one (installment / platform fee) is raised
 *    and paid first;
 *  - a borrower with nothing left to pay: active again at once.
 *
 * Paying an open invoice of a cancelled subscription reactivates it too.
 * Every reactivation tells the subscriber (mail + bell), the host (bell) and,
 * for a client, the client's partner (bell).
 */
#[CoversClass(SubscriptionResumeService::class)]
#[CoversClass(SubscriptionService::class)]
class ResumeSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $hostAdmin;
    private User $partnerAdmin;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->partner = $this->makeWhiteLabelPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->me = $this->makeBorrowerUser($this->partner);
    }

    private function myBorrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    /** A borrower on 12 installments of 558.06, 4 paid, cancelled. */
    private function cancelledInstallments(array $attributes = []): Subscription
    {
        $subscription = Subscription::create(array_merge([
            'entity_type' => Borrower::class,
            'entity_id' => $this->me->entity_id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 4,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.70,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
            'monthly_platform_fee' => 0,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'status' => Subscription::STATUS_CANCELLED,
            'subscription_start_date' => today()->subMonths(4),
            'subscription_end_date' => today()->addDays(10),
        ], $attributes));

        // The down payment, paid long ago: the next bill is an installment.
        $this->invoice($subscription, Invoice::INVOICE_TYPE_DOWN_PAYMENT, 1000.00, Invoice::STATUS_PAID);

        return $subscription;
    }

    private function invoice(Subscription $subscription, string $type, float $amount, string $status): Invoice
    {
        $isBorrower = $subscription->entity_type === Borrower::class;

        return Invoice::create([
            'subscription_id' => $subscription->id,
            'partner_id' => $isBorrower ? $this->partner->id : $subscription->entity_id,
            'borrower_id' => $isBorrower ? $subscription->entity_id : null,
            'entity_type' => $subscription->entity_type,
            'entity_id' => $subscription->entity_id,
            'payment_amount' => $amount,
            'month_count' => 1,
            'status' => $status,
            'type' => $type,
            'currency' => 'USD',
        ]);
    }

    private function resume()
    {
        $this->actingAsUser($this->me);

        return $this->postJson('/api/v1/borrower/resume-subscription');
    }

    private function assertToldOfReactivation(User $subscriberUser, string $subscriberType, string $email, ?User $clientsPartner): void
    {
        Mail::assertQueued(SubscriptionReactivatedMail::class, fn ($mail) => $mail->hasTo($email));
        Notification::assertSentTo($subscriberUser, DynamicNotification::class, fn ($n) => $n->typeKey === $subscriberType);
        Notification::assertSentTo($this->hostAdmin, DynamicNotification::class, fn ($n) => $n->typeKey === 'host.subscription.reactivated');

        if ($clientsPartner) {
            Notification::assertSentTo($clientsPartner, DynamicNotification::class, fn ($n) => $n->typeKey === 'partner.client_subscription.reactivated');
        }
    }

    private function assertNotToldOfReactivation(): void
    {
        Mail::assertNotQueued(SubscriptionReactivatedMail::class);
        Notification::assertNotSentTo($this->hostAdmin, DynamicNotification::class, fn ($n) => $n->typeKey === 'host.subscription.reactivated');
    }

    // ── Borrower: Billing Details › Resume Subscription ────────────────

    #[Test]
    public function before_the_end_date_it_is_active_again_at_once(): void
    {
        $subscription = $this->cancelledInstallments();

        $this->resume()
            ->assertOk()
            ->assertJsonPath('data.status', SubscriptionResumeService::RESULT_ACTIVE)
            ->assertJsonPath('data.invoice_id', null);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(today()->addDays(10)->toDateString(), $subscription->subscription_end_date->toDateString());
        $this->assertSame(1, Invoice::where('subscription_id', $subscription->id)->count());
        $this->assertToldOfReactivation($this->me, 'borrower.subscription.reactivated', $this->myBorrower()->email, $this->partnerAdmin);
    }

    #[Test]
    public function before_the_end_date_an_open_invoice_stays_to_be_charged_as_usual(): void
    {
        $subscription = $this->cancelledInstallments();
        $open = $this->invoice($subscription, Invoice::INVOICE_TYPE_INSTALLMENT, 558.06, Invoice::STATUS_UNPAID);

        $this->resume()->assertOk()->assertJsonPath('data.status', SubscriptionResumeService::RESULT_ACTIVE);

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $open->fresh()->status);
    }

    #[Test]
    public function after_the_end_date_an_open_invoice_must_be_paid_first(): void
    {
        $subscription = $this->cancelledInstallments(['subscription_end_date' => today()->subDays(3)]);
        $open = $this->invoice($subscription, Invoice::INVOICE_TYPE_INSTALLMENT, 558.06, Invoice::STATUS_UNPAID);

        $this->resume()
            ->assertOk()
            ->assertJsonPath('data.status', SubscriptionResumeService::RESULT_PAYMENT_REQUIRED)
            ->assertJsonPath('data.invoice_id', $open->id);

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertSame(1, Invoice::where('subscription_id', $subscription->id)->openCycle()->count());
        $this->assertNotToldOfReactivation();
    }

    #[Test]
    public function after_the_end_date_with_nothing_open_the_next_installment_is_raised_to_pay(): void
    {
        $subscription = $this->cancelledInstallments(['subscription_end_date' => today()->subDays(3)]);

        $response = $this->resume()
            ->assertOk()
            ->assertJsonPath('data.status', SubscriptionResumeService::RESULT_PAYMENT_REQUIRED);

        $raised = Invoice::where('subscription_id', $subscription->id)->openCycle()->sole();
        $response->assertJsonPath('data.invoice_id', $raised->id);
        $this->assertSame(Invoice::INVOICE_TYPE_INSTALLMENT, $raised->type);
        $this->assertEquals(558.06, (float) $raised->payment_amount);
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertNotToldOfReactivation();
    }

    #[Test]
    public function resuming_twice_after_the_end_date_raises_one_invoice(): void
    {
        $subscription = $this->cancelledInstallments(['subscription_end_date' => today()->subDays(3)]);

        $first = $this->resume()->json('data.invoice_id');
        $second = $this->resume()->json('data.invoice_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Invoice::where('subscription_id', $subscription->id)->openCycle()->count());
    }

    #[Test]
    public function a_borrower_with_nothing_left_to_pay_is_active_again_at_once(): void
    {
        $subscription = $this->cancelledInstallments([
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_count' => 1,
            'installments_paid' => 1,
            'current_phase' => Subscription::PHASE_COMPLETED,
            'subscription_end_date' => null,
        ]);

        $this->resume()->assertOk()->assertJsonPath('data.status', SubscriptionResumeService::RESULT_ACTIVE);

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame(0, Invoice::where('subscription_id', $subscription->id)->openCycle()->count());
        $this->assertToldOfReactivation($this->me, 'borrower.subscription.reactivated', $this->myBorrower()->email, $this->partnerAdmin);
    }

    #[Test]
    public function a_subscription_that_is_not_cancelled_cannot_be_resumed(): void
    {
        $subscription = $this->cancelledInstallments(['status' => Subscription::STATUS_ACTIVE]);

        $this->resume()->assertStatus(400)->assertJsonPath('message', __('subscription.resume.not_cancelled'));

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertNotToldOfReactivation();
    }

    #[Test]
    public function with_no_subscription_there_is_nothing_to_resume(): void
    {
        $this->resume()->assertNotFound();
    }

    #[Test]
    public function a_borrower_resumes_only_their_own_subscription(): void
    {
        $theirs = Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $this->makeBorrower($this->partner)->id,
            'status' => Subscription::STATUS_CANCELLED,
            'subscription_end_date' => today()->addDays(10),
        ]);

        $this->resume()->assertNotFound();

        $this->assertSame(Subscription::STATUS_CANCELLED, $theirs->fresh()->status);
    }

    #[Test]
    public function the_clients_partner_is_not_told_twice_when_it_is_the_host(): void
    {
        $hostClient = $this->makeBorrowerUser($this->host);
        Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $hostClient->entity_id,
            'status' => Subscription::STATUS_CANCELLED,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'subscription_end_date' => today()->addDays(10),
        ]);

        $this->actingAsUser($hostClient);
        $this->postJson('/api/v1/borrower/resume-subscription')->assertOk();

        Notification::assertSentToTimes($this->hostAdmin, DynamicNotification::class, 1);
    }

    // ── Partner: My Subscription › Resume Subscription ─────────────────

    private function cancelledPartnerSubscription(array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_count' => 1,
            'installments_paid' => 1,
            'payable_amount' => 5000,
            'monthly_platform_fee' => 299.00,
            'current_phase' => Subscription::PHASE_PLATFORM_FEE,
            'status' => Subscription::STATUS_CANCELLED,
            'subscription_start_date' => today()->subMonths(6),
            'first_payment_date' => today()->subMonths(6),
            'subscription_end_date' => today()->addDays(10),
        ], $attributes));
    }

    #[Test]
    public function a_partner_resumes_before_the_end_date_at_once(): void
    {
        $subscription = $this->cancelledPartnerSubscription();
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/my-subscription/resume-subscription')
            ->assertOk()
            ->assertJsonPath('data.status', SubscriptionResumeService::RESULT_ACTIVE);

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertToldOfReactivation($this->partnerAdmin, 'partner.subscription.reactivated', $this->partner->email, null);
    }

    #[Test]
    public function a_partner_resuming_after_the_end_date_pays_the_next_platform_fee_first(): void
    {
        $subscription = $this->cancelledPartnerSubscription(['subscription_end_date' => today()->subDays(3)]);
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->postJson('/api/v1/common/my-subscription/resume-subscription')
            ->assertOk()
            ->assertJsonPath('data.status', SubscriptionResumeService::RESULT_PAYMENT_REQUIRED);

        $raised = Invoice::where('subscription_id', $subscription->id)->openCycle()->sole();
        $response->assertJsonPath('data.invoice_id', $raised->id);
        $this->assertSame(Invoice::INVOICE_TYPE_PLATFORM_FEE, $raised->type);
        $this->assertEquals(299.00, (float) $raised->payment_amount);
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    #[Test]
    public function partner_staff_cannot_resume_the_partners_subscription(): void
    {
        $subscription = $this->cancelledPartnerSubscription();
        $this->actingAsUser($this->makeStaffUser($this->partner, ['view leads']));

        $this->postJson('/api/v1/common/my-subscription/resume-subscription')->assertForbidden();

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    #[Test]
    public function the_email_says_it_is_active_and_when_the_next_payment_is_due(): void
    {
        $html = (new SubscriptionReactivatedMail([
            'user_name' => 'Bo Rower',
            'next_billing_date' => 'Dec 3, 2026',
            'portal_url' => 'https://portal.example.test/billing-details',
        ], $this->host->id))->render();

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('<strong>Bo</strong>', $html);
        $this->assertStringContainsString('Your subscription is active again.', $html);
        $this->assertStringContainsString('Dec 3, 2026', $html);
        $this->assertStringContainsString('https://portal.example.test/billing-details', $html);
    }

    // ── The invoice says paying it reactivates (the portals warn first) ─

    #[Test]
    public function an_open_invoice_of_a_cancelled_subscription_says_paying_it_reactivates(): void
    {
        $subscription = $this->cancelledInstallments(['subscription_end_date' => today()->subDays(3)]);
        $open = $this->invoice($subscription, Invoice::INVOICE_TYPE_INSTALLMENT, 558.06, Invoice::STATUS_UNPAID);
        $paid = Invoice::where('subscription_id', $subscription->id)->where('status', Invoice::STATUS_PAID)->sole();
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/invoice/{$open->id}")->assertOk()->assertJsonPath('data.reactivates_subscription', true);
        $this->getJson("/api/v1/borrower/invoice/{$paid->id}")->assertOk()->assertJsonPath('data.reactivates_subscription', false);

        $rows = collect($this->postJson('/api/v1/borrower/invoice', [])->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$open->id]['reactivates_subscription']);
        $this->assertFalse($rows[$paid->id]['reactivates_subscription']);
    }

    #[Test]
    public function an_open_invoice_of_an_active_subscription_does_not(): void
    {
        $subscription = $this->cancelledInstallments(['status' => Subscription::STATUS_ACTIVE]);
        $open = $this->invoice($subscription, Invoice::INVOICE_TYPE_INSTALLMENT, 558.06, Invoice::STATUS_UNPAID);
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/invoice/{$open->id}")->assertOk()->assertJsonPath('data.reactivates_subscription', false);
    }

    #[Test]
    public function a_partners_open_invoice_of_its_cancelled_subscription_says_so_too(): void
    {
        $subscription = $this->cancelledPartnerSubscription(['subscription_end_date' => today()->subDays(3)]);
        $open = $this->invoice($subscription, Invoice::INVOICE_TYPE_PLATFORM_FEE, 299.00, Invoice::STATUS_UNPAID);
        $this->actingAsUser($this->makePartnerAdmin($this->partner, ['view invoices']));

        $this->getJson("/api/v1/common/invoices/{$open->id}")->assertOk()->assertJsonPath('data.reactivates_subscription', true);

        $rows = collect($this->postJson('/api/v1/common/invoices/fetch-invoices', [])->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$open->id]['reactivates_subscription']);
    }

    // ── Paying an open invoice of a cancelled subscription ─────────────

    #[Test]
    public function paying_an_open_invoice_reactivates_a_cancelled_subscription(): void
    {
        $subscription = $this->cancelledInstallments(['subscription_end_date' => today()->subDays(3)]);
        $open = $this->invoice($subscription, Invoice::INVOICE_TYPE_INSTALLMENT, 558.06, Invoice::STATUS_UNPAID);
        $open->update(['status' => Invoice::STATUS_PAID]);

        app(SubscriptionService::class)->handleSuccessfulPayment($open->fresh(), $subscription->fresh());

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(5, $subscription->installments_paid);
        // Paid late, so the next cycle runs a month from the day it was paid.
        $this->assertSame(today()->addMonth()->toDateString(), $subscription->subscription_end_date->toDateString());
        $this->assertToldOfReactivation($this->me, 'borrower.subscription.reactivated', $this->myBorrower()->email, $this->partnerAdmin);
    }

    #[Test]
    public function paying_an_invoice_of_an_active_subscription_is_no_reactivation(): void
    {
        $subscription = $this->cancelledInstallments(['status' => Subscription::STATUS_ACTIVE]);
        $open = $this->invoice($subscription, Invoice::INVOICE_TYPE_INSTALLMENT, 558.06, Invoice::STATUS_PAID);

        app(SubscriptionService::class)->handleSuccessfulPayment($open, $subscription->fresh());

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertNotToldOfReactivation();
    }
}
