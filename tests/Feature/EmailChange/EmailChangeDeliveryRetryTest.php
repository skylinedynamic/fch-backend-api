<?php

namespace Tests\Feature\EmailChange;

use App\Mail\Account\EmailChangeConfirmationMail;
use App\Mail\Account\EmailChangeRequestedMail;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * The two email-change mails are sent straight away, not queued, so nothing
 * retries them later. A rate-limited mail account ("550 Too many emails per
 * second") refuses the second of two back-to-back sends; the service has to
 * try again itself, or the new address never gets its confirmation link.
 */
#[CoversClass(EmailChangeService::class)]
class EmailChangeDeliveryRetryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{to: string, mail: string}> every attempt, in order */
    private array $attempts = [];

    /** @var array<string, int> how many times each mail class is refused before it goes through */
    private array $refusals = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.send_gap_ms' => 0, 'mail.send_retry_delays_ms' => [0, 0, 0]]);

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->user = User::create([
            'name' => 'Pat Doe',
            'email' => 'old@example.test',
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $partner->id,
        ]);

        // The mails go out through the Notifier, which hands the addressed
        // Mailable to Mail::send().
        Mail::shouldReceive('send')->andReturnUsing(function ($mail) {
            $this->attempts[] = ['to' => $mail->to[0]['address'], 'mail' => $mail::class];

            if (($this->refusals[$mail::class] ?? 0) > 0) {
                $this->refusals[$mail::class]--;
                throw new TransportException('Expected response code "354" but got code "550", with message "550 5.7.0 Too many emails per second."');
            }

            return null;
        });
    }

    private function deliveredTo(string $email, string $mailClass): int
    {
        return count(array_filter($this->attempts, fn ($a) => $a['to'] === $email && $a['mail'] === $mailClass));
    }

    #[Test]
    public function a_confirmation_refused_for_sending_too_fast_is_sent_again(): void
    {
        $this->refusals[EmailChangeConfirmationMail::class] = 2;

        app(EmailChangeService::class)->request($this->user, 'new@example.test');

        // Refused twice, accepted on the third try.
        $this->assertSame(3, $this->deliveredTo('new@example.test', EmailChangeConfirmationMail::class));
    }

    #[Test]
    public function the_notice_to_the_old_address_is_retried_too(): void
    {
        $this->refusals[EmailChangeRequestedMail::class] = 1;

        app(EmailChangeService::class)->request($this->user, 'new@example.test');

        $this->assertSame(2, $this->deliveredTo('old@example.test', EmailChangeRequestedMail::class));
        $this->assertSame(1, $this->deliveredTo('new@example.test', EmailChangeConfirmationMail::class));
    }

    #[Test]
    public function a_mail_that_goes_through_is_sent_only_once(): void
    {
        app(EmailChangeService::class)->request($this->user, 'new@example.test');

        $this->assertSame(1, $this->deliveredTo('old@example.test', EmailChangeRequestedMail::class));
        $this->assertSame(1, $this->deliveredTo('new@example.test', EmailChangeConfirmationMail::class));
    }

    #[Test]
    public function giving_up_after_the_last_retry_still_leaves_the_change_pending(): void
    {
        // Refused on every attempt: the request isn't undone, it can be resent.
        $this->refusals[EmailChangeConfirmationMail::class] = 99;

        app(EmailChangeService::class)->request($this->user, 'new@example.test');

        $this->assertSame(4, $this->deliveredTo('new@example.test', EmailChangeConfirmationMail::class));
        $this->assertSame('new@example.test', $this->user->refresh()->pending_email);
    }
}
