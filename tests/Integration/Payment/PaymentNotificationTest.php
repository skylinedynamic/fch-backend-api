<?php

namespace Tests\Integration\Payment;

use App\Mail\Payment\PaymentApprovedMail;
use App\Mail\Payment\PaymentRejectedMail;
use App\Mail\Payment\PaymentSubmittedMail;
use App\Mail\Payment\PaymentSuccessMail;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\GlobalPayment\PaymentMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A payment event tells the payer and, where it always emailed them, the
 * receiver - each in their own bell as well as by the email they already got.
 *
 * Borrower pays their partner here, so the receiver is the partner.
 */
#[CoversClass(PaymentMailService::class)]
class PaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private Borrower $borrower;
    private User $partnerUser;
    private User $borrowerUser;
    private Invoice $invoice;
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        Partner::create(['is_host' => true, 'company_name' => 'Host LLC', 'email' => 'host@example.test']);
        $this->partner = Partner::create([
            'is_host' => false,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Northwind Capital',
            'full_name' => 'Nora North',
            'email' => 'accounts@northwind.test',
        ]);
        $this->borrower = Borrower::findOrFail(DB::table('borrowers')->insertGetId([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.test',
            'partner_id' => $this->partner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->partnerUser = $this->user(Partner::class, $this->partner->id, User::PARTNER);
        $this->borrowerUser = $this->user(Borrower::class, $this->borrower->id, User::BORROWER);

        $this->invoice = Invoice::create([
            'invoice_id' => 'INV-42',
            'partner_id' => $this->partner->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'email' => $this->borrower->email,
            'payment_amount' => 150,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->payment = Payment::create([
            'invoice_id' => $this->invoice->id,
            'partner_id' => $this->partner->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'amount' => 150,
            'currency' => 'USD',
            'transaction_id' => 'txn_1',
            'payment_from' => 'Bank Transfer',
        ]);
    }

    private function user(string $entityType, int $entityId, string $userType): User
    {
        return User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'User ' . uniqid(),
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'partner_id' => $this->partner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function assertBelled(User $user, string $type, string $body): void
    {
        Notification::assertSentTo($user, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === $type
            && $n->toArray($user)['body'] === $body
            && $n->toArray($user)['url'] === "/payments/{$this->payment->id}");
    }

    private function service(): PaymentMailService
    {
        return app(PaymentMailService::class);
    }

    #[Test]
    public function a_successful_payment_tells_the_payer_and_the_partner_it_was_paid_to(): void
    {
        $this->service()->sendSuccess($this->payment, $this->invoice, 'Stripe');

        $this->assertBelled($this->borrowerUser, 'payment.succeeded', 'Your payment of USD 150.00 for invoice INV-42 went through.');
        $this->assertBelled($this->partnerUser, 'payment.received', 'Jane Smith paid USD 150.00 for invoice INV-42.');
        Mail::assertQueued(PaymentSuccessMail::class, fn ($mail) => $mail->hasTo('jane@example.test'));
        Mail::assertQueued(PaymentSuccessMail::class, fn ($mail) => $mail->hasTo('accounts@northwind.test'));
    }

    #[Test]
    public function a_submitted_manual_payment_asks_the_partner_to_review_it(): void
    {
        $this->service()->sendSubmitted($this->payment, $this->invoice);

        $this->assertBelled($this->borrowerUser, 'payment.submitted', 'Your payment of USD 150.00 for invoice INV-42 is awaiting confirmation.');
        $this->assertBelled($this->partnerUser, 'payment.submitted_for_review', 'Jane Smith submitted a payment of USD 150.00 for invoice INV-42.');
        Mail::assertQueued(PaymentSubmittedMail::class, 2);
    }

    #[Test]
    public function an_approval_and_a_rejection_go_to_the_payer_only(): void
    {
        $this->service()->sendApproved($this->payment, $this->invoice);
        $this->service()->sendRejected($this->payment, $this->invoice, 'Unreadable slip');

        $this->assertBelled($this->borrowerUser, 'payment.approved', 'Your payment of USD 150.00 for invoice INV-42 was approved.');
        $this->assertBelled($this->borrowerUser, 'payment.rejected', 'Your payment of USD 150.00 for invoice INV-42 was rejected.');
        Notification::assertNotSentTo($this->partnerUser, DynamicNotification::class);
        Mail::assertQueued(PaymentApprovedMail::class, fn ($mail) => $mail->hasTo('jane@example.test'));
        Mail::assertQueued(PaymentRejectedMail::class, fn ($mail) => $mail->hasTo('jane@example.test'));
    }

    #[Test]
    public function a_payer_with_no_email_is_still_told_in_the_bell(): void
    {
        $this->borrower->update(['email' => null]);
        $this->invoice->update(['email' => null]);

        $this->service()->sendApproved($this->payment->fresh(), $this->invoice->fresh());

        Mail::assertNothingOutgoing();
        $this->assertBelled($this->borrowerUser, 'payment.approved', 'Your payment of USD 150.00 for invoice INV-42 was approved.');
    }
}
