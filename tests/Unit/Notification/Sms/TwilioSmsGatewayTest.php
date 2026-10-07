<?php

namespace Tests\Unit\Notification\Sms;

use App\Models\Setting\SmsConfiguration;
use App\Services\FCH\Common\Notification\Sms\SmsDeliveryException;
use App\Services\FCH\Common\Notification\Sms\TwilioSmsGateway;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Twilio's Messages API, called with the partner's own account.
 */
#[CoversClass(TwilioSmsGateway::class)]
class TwilioSmsGatewayTest extends TestCase
{
    private function gateway(array $credentials = [], ?string $from = '+15005550006'): TwilioSmsGateway
    {
        return new TwilioSmsGateway(new SmsConfiguration([
            'provider' => 'twilio',
            'from_number' => $from,
            'credentials' => array_merge(['account_sid' => 'AC123', 'auth_token' => 'tok'], $credentials),
        ]));
    }

    #[Test]
    public function it_posts_the_message_with_the_accounts_credentials(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->gateway()->send('+15551234567', 'Hello');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('AC123:tok'))
            && $request['To'] === '+15551234567'
            && $request['From'] === '+15005550006'
            && $request['Body'] === 'Hello');
    }

    #[Test]
    public function a_messaging_service_is_used_instead_of_a_from_number_when_set(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->gateway(['messaging_service_sid' => 'MG123'])->send('+15551234567', 'Hello');

        Http::assertSent(fn ($request) => $request['MessagingServiceSid'] === 'MG123' && !isset($request['From']));
    }

    #[Test]
    public function a_rejected_message_throws_with_twilios_reason(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'The number is unverified'], 400)]);

        $this->expectException(SmsDeliveryException::class);
        $this->expectExceptionMessage('The number is unverified');

        $this->gateway()->send('+15551234567', 'Hello');
    }
}
