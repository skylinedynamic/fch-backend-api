<?php

namespace App\Services\Global\Calendly;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Something Calendly refused or could not do, with the status our own API
 * answers with and a message fit to show the user. Calendly's own error text
 * is passed on where it explains the refusal (a slot no longer free); its
 * outages are not.
 */
class CalendlyException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly ?int $calendlyStatus = null,
    ) {
        parent::__construct($message);
    }

    /** The status our API answers with. */
    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** The status Calendly answered with, when it was Calendly that refused. */
    public function calendlyStatus(): ?int
    {
        return $this->calendlyStatus;
    }

    public static function notConnected(): self
    {
        return new self(__('calendly.not_connected'), 422);
    }

    public static function notConfigured(): self
    {
        return new self(__('calendly.not_configured'), 422);
    }

    public static function accountInUse(): self
    {
        return new self(__('calendly.account_in_use'), 422);
    }

    public static function needsReconnect(): self
    {
        return new self(__('calendly.needs_reconnect'), 422);
    }

    public static function authorizationFailed(): self
    {
        return new self(__('calendly.authorization_failed'), 422);
    }

    public static function forbidden(): self
    {
        return new self(__('calendly.cannot_book_with_host'), 403);
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();

        if ($status === 429) {
            // When Calendly says when it takes bookings again (Retry-After, or its rate-limit
            // reset: seconds from now, or a Unix time), the message says it.
            $wait = self::secondsUntilRetry($response);
            $minutes = $wait ? (int) ceil($wait / 60) : 0;
            // Over two hours it reads in hours ("24 hours", not "1440 minutes").
            $hours = (int) ceil($minutes / 60);

            return new self(match (true) {
                ! $wait => __('calendly.rate_limited'),
                $minutes >= 120 => trans_choice('calendly.rate_limited_for_hours', $hours, ['hours' => $hours, 'limit' => $response->header('X-RateLimit-Limit') ?: '?']),
                default => trans_choice('calendly.rate_limited_for', $minutes, ['minutes' => $minutes]),
            }, 429, $status);
        }

        if ($status >= 500 || $status === 401) {
            return new self(__('calendly.unavailable'), 502, $status);
        }

        // Calendly's `message` on a 400 is only "The supplied parameters are
        // invalid."; which field and why is in `details`.
        $message = $response->json('details.0.message') ?: $response->json('message') ?: $response->json('title') ?: __('calendly.refused');

        return new self((string) $message, 422, $status);
    }

    /**
     * How long until Calendly takes requests again, from its 429's headers:
     * Retry-After (seconds), or X-RateLimit-Reset (seconds from now, or a Unix
     * time). Null when it does not say.
     */
    public static function secondsUntilRetry(Response $response): ?int
    {
        foreach (['Retry-After', 'X-RateLimit-Reset'] as $header) {
            $value = trim((string) $response->header($header));
            if ($value === '' || ! is_numeric($value)) {
                continue;
            }
            $seconds = (int) $value;
            // A Unix time rather than a number of seconds.
            if ($seconds > 1_000_000_000) {
                $seconds -= time();
            }
            if ($seconds > 0) {
                return $seconds;
            }
        }

        return null;
    }
}
