<?php

namespace Tests\Unit\Mail;

use App\Mail\Contracts\SendsImmediately;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every Mailable states how it is delivered: queued (ShouldQueue) or sent
 * inline (SendsImmediately) - exactly one of the two.
 *
 * Queued is the default, so a request never waits on an SMTP round trip and
 * MailConfig can retry a transient refusal. Inline is for the few messages a
 * person is actively waiting on - account credentials, password reset, a
 * security code - which also keeps a plaintext password out of the jobs and
 * failed_jobs tables. Leaving a new Mailable undeclared used to mean "inline"
 * by accident; this makes it a decision.
 *
 * No database: reads class declarations only.
 */
class MailQueuePolicyTest extends TestCase
{
    /**
     * The messages someone is waiting on. Everything else is queued.
     */
    private const IMMEDIATE = [
        \App\Mail\AccountCreateNotificationMail::class,
        \App\Mail\Borrower\BorrowerAccountCreatedMail::class,
        \App\Mail\Borrower\BorrowerPaymentLinkMail::class,
        \App\Mail\Partner\PartnerAccountUpdateMail::class,
        \App\Mail\Partner\PartnerPaymentLinkMail::class,
        \App\Mail\Payment\PaymentSuccessUserMail::class,
        \App\Mail\Trial\BorrowerTrialMail::class,
        \App\Mail\Trial\PartnerTrialMail::class,
        \App\Services\Global\Notification\Mail\UserCreateNotificationMail::class,
        // Password reset, two-factor codes and the password-changed notice.
        \App\Services\Global\Notification\Mail\GenericNotificationMail::class,
    ];

    public static function mailables(): array
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root . '/app/Mail/*.php'),
            glob($root . '/app/Mail/*/*.php'),
            glob($root . '/app/Services/Global/Notification/Mail/*.php'),
        );

        $cases = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (!preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
                || !preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class)) {
                continue;
            }

            $fqcn = $namespace[1] . '\\' . $class[1];

            if (is_subclass_of($fqcn, Mailable::class)) {
                $cases[$class[1]] = [$fqcn];
            }
        }

        return $cases;
    }

    #[Test]
    public function the_scan_finds_the_mailables(): void
    {
        // Guards the provider: a broken glob would make every case below vanish
        // and the policy pass vacuously.
        $this->assertGreaterThan(40, count(self::mailables()));
    }

    #[Test]
    #[DataProvider('mailables')]
    public function each_mailable_declares_exactly_one_delivery_mode(string $mailable): void
    {
        $queued = is_subclass_of($mailable, ShouldQueue::class);
        $immediate = is_subclass_of($mailable, SendsImmediately::class);

        $this->assertTrue(
            $queued xor $immediate,
            "{$mailable} must implement exactly one of ShouldQueue or SendsImmediately"
        );
    }

    #[Test]
    #[DataProvider('mailables')]
    public function only_the_messages_someone_waits_on_skip_the_queue(string $mailable): void
    {
        $this->assertSame(
            in_array($mailable, self::IMMEDIATE, true),
            is_subclass_of($mailable, SendsImmediately::class),
            "{$mailable} is " . (in_array($mailable, self::IMMEDIATE, true) ? 'an immediate' : 'a queued') . ' message'
        );
    }
}
