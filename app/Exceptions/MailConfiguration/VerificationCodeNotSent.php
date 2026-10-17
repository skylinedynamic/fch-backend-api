<?php

namespace App\Exceptions\MailConfiguration;

use RuntimeException;

/**
 * The verification code could not be sent through the settings being verified.
 * The message is safe to show; the mail server's own error is the previous
 * exception, and is logged rather than returned.
 */
class VerificationCodeNotSent extends RuntimeException
{
}
