<?php

namespace App\Services\Global\Calendly;

/**
 * Calendly's webhook signature: the Calendly-Webhook-Signature header is
 * "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" keyed with the
 * subscription's signing key>". A delivery is genuine when the HMAC matches,
 * and fresh when t is within the tolerance of now - an old delivery replayed
 * is refused.
 */
final class CalendlyWebhookSignature
{
    public const HEADER = 'Calendly-Webhook-Signature';

    /** Seconds either side of now a signature's time may be. */
    public const TOLERANCE_SECONDS = 180;

    public static function isValid(?string $header, string $body, ?string $signingKey, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        if (empty($signingKey) || empty($header)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$name] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        if (abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $signingKey), $signature);
    }
}
