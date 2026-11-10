<?php

namespace Tests\Unit\Calendly;

use App\Services\Global\Calendly\CalendlyWebhookSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Calendly signs each webhook delivery: Calendly-Webhook-Signature is
 * "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the signing key>".
 * Only a delivery that carries a matching signature, made recently, is accepted.
 */
#[CoversClass(CalendlyWebhookSignature::class)]
class CalendlyWebhookSignatureTest extends TestCase
{
    private const KEY = 'signing-key';

    private const BODY = '{"event":"invitee.created","payload":{}}';

    private const NOW = 1_800_000_000;

    private function header(string $body = self::BODY, int $timestamp = self::NOW, string $key = self::KEY): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $key);
    }

    #[Test]
    public function a_delivery_signed_with_the_key_just_now_is_accepted(): void
    {
        $this->assertTrue(CalendlyWebhookSignature::isValid($this->header(), self::BODY, self::KEY, self::NOW));
    }

    #[Test]
    public function a_delivery_signed_a_little_while_ago_is_still_accepted(): void
    {
        $this->assertTrue(CalendlyWebhookSignature::isValid($this->header(timestamp: self::NOW - 120), self::BODY, self::KEY, self::NOW));
    }

    public static function rejected(): array
    {
        $t = self::NOW;
        $good = hash_hmac('sha256', $t.'.'.self::BODY, self::KEY);

        return [
            'no header' => [null],
            'empty header' => [''],
            'no timestamp' => ["v1={$good}"],
            'no signature' => ["t={$t}"],
            'signed with another key' => ['t='.$t.',v1='.hash_hmac('sha256', $t.'.'.self::BODY, 'other-key')],
            'body changed after signing' => ['t='.$t.',v1='.hash_hmac('sha256', $t.'.{"event":"x"}', self::KEY)],
            'timestamp changed after signing' => ['t='.($t - 1).",v1={$good}"],
            'signed too long ago (replay)' => ['t='.($t - 600).',v1='.hash_hmac('sha256', ($t - 600).'.'.self::BODY, self::KEY)],
            'signed in the future' => ['t='.($t + 600).',v1='.hash_hmac('sha256', ($t + 600).'.'.self::BODY, self::KEY)],
        ];
    }

    #[Test]
    #[DataProvider('rejected')]
    public function a_delivery_without_a_matching_recent_signature_is_refused(?string $header): void
    {
        $this->assertFalse(CalendlyWebhookSignature::isValid($header, self::BODY, self::KEY, self::NOW));
    }

    #[Test]
    public function nothing_is_accepted_when_no_signing_key_is_configured(): void
    {
        $this->assertFalse(CalendlyWebhookSignature::isValid($this->header(key: ''), self::BODY, '', self::NOW));
    }
}
