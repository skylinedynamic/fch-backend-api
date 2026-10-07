<?php

namespace Tests\Unit\Notification\Sms;

use App\Services\FCH\Common\Notification\Sms\SmsGateway;
use App\Support\Locations;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/sms.php only lists which countries and providers SMS is offered
 * for; everything about a country itself comes from config/locations.php.
 * These keep the two from drifting apart.
 */
class SmsConfigTest extends TestCase
{
    #[Test]
    public function sms_is_offered_for_the_united_states_and_canada(): void
    {
        $this->assertSame(['USA', 'CAN'], array_keys(config('sms.countries')));
    }

    #[Test]
    public function every_sms_country_exists_in_locations_with_a_phone_format(): void
    {
        $locations = Locations::map();

        foreach (array_keys(config('sms.countries')) as $code) {
            $this->assertArrayHasKey($code, $locations, "{$code} is not in config/locations.php");
            $this->assertNotEmpty($locations[$code]['phone_code'] ?? null, "{$code} has no phone_code");
            $this->assertIsInt($locations[$code]['phone_national_length'] ?? null, "{$code} has no phone_national_length");
        }
    }

    #[Test]
    public function every_offered_provider_is_defined_with_a_gateway(): void
    {
        $providers = config('sms.providers');

        foreach (config('sms.countries') as $code => $country) {
            foreach ($country['providers'] as $provider) {
                $this->assertArrayHasKey($provider, $providers, "{$code} offers unknown provider {$provider}");
                $this->assertTrue(is_a($providers[$provider]['gateway'], SmsGateway::class, true));
            }
        }
    }
}
