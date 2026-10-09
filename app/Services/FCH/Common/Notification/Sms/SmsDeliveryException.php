<?php

namespace App\Services\FCH\Common\Notification\Sms;

use RuntimeException;

/**
 * The provider refused a message. The message is the provider's own reason,
 * which is what an admin needs to fix their settings (wrong token, an
 * unverified number on a trial account...). It never carries credentials.
 */
class SmsDeliveryException extends RuntimeException
{
}
