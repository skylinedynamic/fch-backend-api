<?php

namespace Tests\Integration\Mail;

use App\Mail\AccountCreateNotificationMail;
use App\Mail\ActivityLog\ActivityLogMail;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Mail\Borrower\BorrowerMigrationMail;
use App\Mail\Borrower\BorrowerPaymentLinkMail;
use App\Mail\Borrower\BorrowerVerificationStatusMail;
use App\Mail\Borrower\ChangeRequestStatusMail;
use App\Mail\CreditBuilder\TaskVerificationRequestedMail;
use App\Mail\CronJobFailureMail;
use App\Mail\Partner\PartnerAccountUpdateMail;
use App\Mail\Partner\PartnerMigrationMail;
use App\Mail\Partner\PartnerPaymentLinkMail;
use App\Mail\Payment\CardReauthorizationRequiredMail;
use App\Mail\Payment\PaymentApprovedMail;
use App\Mail\Payment\PaymentFailedMail;
use App\Mail\Payment\PaymentRejectedMail;
use App\Mail\Payment\PaymentSubmittedMail;
use App\Mail\Payment\PaymentSuccessMail;
use App\Mail\Payment\PaymentSuccessUserMail;
use App\Mail\SystemHealthAlert;
use App\Mail\Trial\BorrowerTrialMail;
use App\Mail\Trial\PartnerTrialExpiryUpdatedMail;
use App\Mail\Trial\PartnerTrialMail;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Every account, trial, payment and operations email renders - as the queue
 * worker would render it - from data shaped like its real call site, and the
 * values it was given reach the body. A Blade or key error here would
 * otherwise first surface as a failed job in production.
 */
class AccountAndPaymentMailRenderTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner(['company_name' => 'Four Corner Funding']);
        $this->partner = $this->makeWhiteLabelPartner(['company_name' => 'Lakeside Capital', 'free_trail_end_date' => '2026-07-15']);
        $this->user = $this->makeBorrowerUser($this->partner, [], ['name' => 'Dana Whitfield', 'email' => 'dana@example.test']);
    }

    private function businessInfo(): array
    {
        return [
            'company_name' => 'Four Corner Funding',
            'email' => 'hello@fourcorner.test',
            'phone_number' => '+1 555 0100',
            'address_line_1' => '1 Main Street',
            'company_logo' => 'logo.png',
        ];
    }

    private function payment(array $extra = []): array
    {
        return array_merge([
            'partner_id' => $this->partner->id,
            'amount' => 149.5,
            'currency' => 'USD',
            'transaction_id' => 'TXN-55120',
            'invoice_id' => 'INV-0042',
            'payment_method' => 'Bank Transfer',
            'description' => 'Program payment',
            'bank_name' => 'First Bank',
            'account_name' => 'Lakeside Capital',
            'date' => now(),
            'payer_name' => 'Dana Whitfield',
            'payer_email' => 'dana@example.test',
            'user_name' => 'Dana Whitfield',
            'is_receiver' => false,
            'subject' => 'Payment for INV-0042',
        ], $extra);
    }

    public static function mailables(): array
    {
        return [
            'staff account created' => [fn (self $t) => new AccountCreateNotificationMail(['user' => $t->user, 'plain_password' => 'Temp#4821', 'business_info' => $t->businessInfo()]), 'Temp#4821'],
            'borrower account created' => [fn (self $t) => new BorrowerAccountCreatedMail(['user' => $t->user, 'plain_password' => 'Temp#4821', 'business_info' => $t->businessInfo()], $t->partner->id), 'Temp#4821'],
            'borrower login updated' => [fn (self $t) => new BorrowerAccountCreatedMail(['user' => $t->user, 'plain_password' => 'Temp#4821', 'business_info' => $t->businessInfo(), 'is_update' => true], $t->partner->id), 'dana@example.test'],
            'borrower migrated' => [fn (self $t) => new BorrowerMigrationMail(['user' => $t->user, 'business_info' => $t->businessInfo(), 'payment_link' => 'https://pay.example.test/m/abc', 'amount' => 49], $t->partner->id), '$49.00'],
            'borrower payment link' => [fn (self $t) => new BorrowerPaymentLinkMail(['user' => $t->user, 'business_info' => $t->businessInfo(), 'payment_link' => 'https://pay.example.test/p/xyz', 'amount' => 49, 'plain_password' => 'Temp#4821'], $t->partner->id), 'https://pay.example.test/p/xyz'],
            'borrower trial' => [fn (self $t) => new BorrowerTrialMail(['user' => $t->user, 'business_info' => $t->businessInfo(), 'plain_password' => 'Temp#4821', 'trial_end_date' => '2026-07-15'], $t->partner->id), 'Temp#4821'],
            'partner trial' => [fn (self $t) => new PartnerTrialMail(['partner_id' => $t->partner->id, 'user' => $t->user, 'plain_password' => 'Temp#4821', 'trial_end_date' => '2026-07-15', 'business_info' => $t->businessInfo()], $t->host->id), 'Temp#4821'],
            'partner trial extended' => [fn (self $t) => new PartnerTrialExpiryUpdatedMail(['partner_id' => $t->partner->id, 'partner' => $t->partner, 'user' => $t->user, 'trial_end_date' => '2026-08-15', 'old_trial_end_date' => '2026-07-15', 'business_info' => $t->businessInfo()], $t->host->id), 'August 15, 2026'],
            'partner login updated' => [fn (self $t) => new PartnerAccountUpdateMail(['partner_id' => $t->partner->id, 'user' => $t->user, 'plain_password' => 'Temp#4821', 'business_info' => $t->businessInfo(), 'is_update' => true], $t->host->id), 'Temp#4821'],
            'partner migrated' => [fn (self $t) => new PartnerMigrationMail(['partner_id' => $t->partner->id, 'user' => $t->user, 'business_info' => $t->businessInfo(), 'payment_link' => 'https://pay.example.test/pm/1', 'amount' => 99, 'enrollment_type' => 'Monthly'], $t->host->id), 'https://pay.example.test/pm/1'],
            'partner payment link' => [fn (self $t) => new PartnerPaymentLinkMail(['partner_id' => $t->partner->id, 'user' => $t->user, 'business_info' => $t->businessInfo(), 'payment_link' => 'https://pay.example.test/pp/1', 'amount' => 99, 'plain_password' => 'Temp#4821', 'enrollment_type' => 'Monthly'], $t->host->id), 'https://pay.example.test/pp/1'],
            'verification decided' => [fn (self $t) => new BorrowerVerificationStatusMail(['subject' => 'Verification update', 'borrower_name' => 'Dana Whitfield', 'status' => 'approved', 'note' => 'All documents check out', 'task_results' => [['task_name' => 'Open a business bank account', 'tier_name' => 'Tier 1', 'is_verified' => true]]], $t->partner->id), 'Open a business bank account'],
            'change request decided' => [fn (self $t) => new ChangeRequestStatusMail(['subject' => 'Change request', 'borrower_name' => 'Dana Whitfield', 'field_name' => 'Business phone', 'status' => 'Approved', 'new_value' => '+1 555 0199', 'note' => null], $t->partner->id), '+1 555 0199'],
            'verification requested' => [fn (self $t) => new TaskVerificationRequestedMail(['borrower_name' => 'Dana Whitfield', 'borrower_email' => 'dana@example.test', 'borrower_id' => $t->user->entity_id, 'host_name' => 'Four Corner Funding', 'partner_name' => 'Lakeside Capital', 'tasks' => [['task_name' => 'Get a DUNS number', 'tier_name' => 'Tier 2']]], $t->host->id), 'Get a DUNS number'],
            'card reauthorization' => [fn (self $t) => new CardReauthorizationRequiredMail($t->payment(['card_no' => '4242', 'portal_url' => 'https://app.example.test/billing']), $t->partner->id), 'INV-0042'],
            'payment submitted' => [fn (self $t) => new PaymentSubmittedMail($t->payment(), $t->partner->id), 'TXN-55120'],
            'payment submitted (receiver)' => [fn (self $t) => new PaymentSubmittedMail($t->payment(['is_receiver' => true, 'payment_url' => 'https://admin.example.test/payments/7']), $t->partner->id), 'Dana Whitfield'],
            'payment success' => [fn (self $t) => new PaymentSuccessMail($t->payment(), $t->partner->id), 'TXN-55120'],
            'payment approved' => [fn (self $t) => new PaymentApprovedMail($t->payment(['approval_note' => 'Funds cleared']), $t->partner->id), 'TXN-55120'],
            'payment rejected' => [fn (self $t) => new PaymentRejectedMail($t->payment(['rejection_reason' => 'Reference did not match']), $t->partner->id), 'Reference did not match'],
            'seat activated' => [fn (self $t) => new PaymentSuccessUserMail(['amount' => 25, 'currency' => 'USD', 'transaction_id' => 'SUB_9', 'description' => 'User seat activation', 'date' => now(), 'user_name' => 'Dana Whitfield', 'user_email' => 'dana@example.test', 'plainPassword' => 'Seat#7731', 'user' => $t->user], $t->partner->id), 'Seat#7731'],
            'payment failed' => [fn (self $t) => new PaymentFailedMail($t->payment(), $t->partner->id), 'TXN-55120'],
            'cron failure' => [fn (self $t) => new CronJobFailureMail(['job_name' => 'subscriptions:process-all', 'error_message' => 'Scheduled command failed', 'timestamp' => '2026-06-15 01:00:00', 'stack_trace' => 'Scheduled command failed: subscriptions:process-all']), 'subscriptions:process-all'],
            'system health alert' => [fn (self $t) => new SystemHealthAlert([['metric' => 'Disk Space', 'usage' => 0.97, 'threshold' => 0.95]], ['CPU Usage' => 0.4, 'RAM Usage' => 0.5, 'Disk Space' => 0.97]), 'Disk Space'],
            'activity log email' => [fn (self $t) => new ActivityLogMail(['subject' => 'Your documents', 'body' => '<p>Please review the attached term sheet.</p>'], [], []), 'Please review the attached term sheet.'],
        ];
    }

    #[Test]
    #[DataProvider('mailables')]
    public function the_email_renders_with_its_data(Closure $make, string $expected): void
    {
        /** @var Mailable $mail */
        $mail = $make($this);

        $html = $mail->render();

        $this->assertStringContainsString($expected, $html);
        $this->assertNotEmpty($mail->subject ?? $mail->envelope()->subject);
    }
}
