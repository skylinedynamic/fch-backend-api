<?php

namespace Tests\Unit\Notification\Sms;

use App\Models\Setting\SmsConfiguration;
use App\Services\FCH\Common\Notification\Sms\SmsGatewayFactory;
use App\Services\FCH\Common\Notification\Sms\TwilioSmsGateway;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which class sends for a provider is configuration
 * (sms.providers), so adding a provider is a new gateway class
 * plus a config entry.
 */
#[CoversClass(SmsGatewayFactory::class)]
class SmsGatewayFactoryTest extends TestCase
{
    #[Test]
    public function twilio_configurations_send_through_the_twilio_gateway(): void
    {
        $gateway = (new SmsGatewayFactory())->make(new SmsConfiguration(['provider' => 'twilio']));

        $this->assertInstanceOf(TwilioSmsGateway::class, $gateway);
    }

    #[Test]
    public function an_unknown_provider_fails_loudly_instead_of_silently_dropping_sms(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SmsGatewayFactory())->make(new SmsConfiguration(['provider' => 'nope']));
    }
}
