<?php

namespace Tests\Unit\Notification\Sms;

use App\Models\Setting\SmsConfiguration;
use App\Services\FCH\Common\Notification\Sms\SmsDeliveryException;
use App\Services\FCH\Common\Notification\Sms\SmsGateway;
use App\Services\FCH\Common\Notification\Sms\SmsGatewayFactory;
use App\Services\FCH\Common\Notification\Sms\SmsSender;
use App\Services\FCH\Common\Setting\SmsConfigurationService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Every text - notifications and 2FA codes - goes through SmsSender. It never
 * throws: a text that cannot go out is logged and reported as not sent, so
 * whatever triggered it carries on.
 */
#[CoversClass(SmsSender::class)]
class SmsSenderTest extends TestCase
{
    private RecordingSmsGateway $gateway;

    /**
     * @param array<int, SmsConfiguration> $configurations partner id => its resolved configuration
     */
    private function sender(array $configurations, ?SmsGateway $gateway = null): SmsSender
    {
        $this->gateway = new RecordingSmsGateway();
        $gateway ??= $this->gateway;

        $service = new class($configurations) extends SmsConfigurationService {
            public function __construct(private array $configurations)
            {
            }

            public function resolveFor(?int $partnerId): ?SmsConfiguration
            {
                return $this->configurations[$partnerId] ?? null;
            }
        };

        $factory = new class($gateway) extends SmsGatewayFactory {
            public function __construct(private SmsGateway $gateway)
            {
            }

            public function make(SmsConfiguration $configuration): SmsGateway
            {
                return $this->gateway;
            }
        };

        return new SmsSender($service, $factory);
    }

    private function us(): SmsConfiguration
    {
        return new SmsConfiguration(['country' => 'USA', 'provider' => 'twilio']);
    }

    #[Test]
    public function it_sends_to_the_normalised_number_through_the_partners_account(): void
    {
        $sent = $this->sender([5 => $this->us()])->send('(555) 123-4567', 5, 'Hello');

        $this->assertTrue($sent);
        $this->assertSame([['+15551234567', 'Hello']], $this->gateway->sent);
    }

    #[Test]
    public function nothing_is_sent_when_the_partner_has_no_enabled_account(): void
    {
        $this->assertFalse($this->sender([])->send('5551234567', 5, 'Hello'));
        $this->assertSame([], $this->gateway->sent);
    }

    #[Test]
    public function nothing_is_sent_to_a_missing_or_unusable_number(): void
    {
        $sender = $this->sender([5 => $this->us()]);

        $this->assertFalse($sender->send(null, 5, 'Hello'));
        $this->assertFalse($sender->send('', 5, 'Hello'));
        $this->assertFalse($sender->send('123', 5, 'Hello'));
        $this->assertSame([], $this->gateway->sent);
    }

    #[Test]
    public function a_provider_rejection_is_logged_and_reported_as_not_sent(): void
    {
        Log::spy();
        $failing = new class implements SmsGateway {
            public function send(string $to, string $message): void
            {
                throw new SmsDeliveryException('Authenticate');
            }
        };

        $sent = $this->sender([5 => $this->us()], $failing)->send('5551234567', 5, 'Hello', ['type' => 'x']);

        $this->assertFalse($sent);
        Log::shouldHaveReceived('error')->withArgs(fn (string $line, array $context) => $line === __('notification.sms.errors.send_failed')
            && $context['reason'] === 'Authenticate'
            && $context['to'] === '******4567'
            && $context['type'] === 'x');
    }

    #[Test]
    public function any_other_failure_is_caught_too(): void
    {
        $failing = new class implements SmsGateway {
            public function send(string $to, string $message): void
            {
                throw new RuntimeException('connection timed out');
            }
        };

        $this->assertFalse($this->sender([5 => $this->us()], $failing)->send('5551234567', 5, 'Hello'));
    }

    #[Test]
    public function an_unusable_number_is_logged_masked(): void
    {
        Log::spy();

        $this->sender([5 => $this->us()])->send('555-1234567-89', 5, 'Hello');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $line, array $context) => $line === __('notification.sms.errors.invalid_number')
            && $context['to'] === '********6789');
    }
}
