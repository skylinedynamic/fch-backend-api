<?php

namespace App\Services\FCH\Common\Notification\Sms;

use App\Models\Setting\SmsConfiguration;
use InvalidArgumentException;

/**
 * Builds the gateway for a partner's SMS configuration, from the provider's
 * `gateway` class in config/sms.php (providers).
 */
class SmsGatewayFactory
{
    public function make(SmsConfiguration $configuration): SmsGateway
    {
        $class = config('sms.providers', [])[$configuration->provider]['gateway'] ?? null;

        // An unknown provider throws rather than quietly dropping every text.
        if (!is_string($class) || !is_a($class, SmsGateway::class, true)) {
            throw new InvalidArgumentException(__('notification.sms.errors.provider_not_configured', [
                'provider' => (string) $configuration->provider,
            ]));
        }

        return new $class($configuration);
    }
}
