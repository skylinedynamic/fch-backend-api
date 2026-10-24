<?php

namespace App\Support\Payment;

use App\Rules\StripeKeysMatchPaymentMode;

/**
 * What a usable pair of Stripe keys looks like.
 *
 * Stripe keys state their type and account in their prefix: `pk_` publishable,
 * `sk_` secret, `rk_` restricted secret, then `test_` or `live_`. A pair can
 * only take a payment when the publishable key is one, the secret key is one,
 * and both are from the same side (a card tokenized against the test account
 * can't be charged with a live key - Stripe answers "No such PaymentMethod").
 *
 * Which mode the pair is saved under is deliberately not checked here: test
 * keys under Production do take (test) payments, and StripeKeysMatchPaymentMode
 * already refuses new ones when they are saved.
 */
final class StripeKeys
{
    private const PUBLISHABLE = '/^pk_(test|live)_\S+$/';
    private const SECRET = '/^(sk|rk)_(test|live)_\S+$/';

    public static function isPublishable(mixed $key): bool
    {
        return is_string($key) && preg_match(self::PUBLISHABLE, $key) === 1;
    }

    public static function isSecret(mixed $key): bool
    {
        return is_string($key) && preg_match(self::SECRET, $key) === 1;
    }

    /**
     * Why this pair can't take a payment, as messages for the partner; none when it can.
     *
     * @return list<string>
     */
    public static function problems(?string $publishable, ?string $secret, string $modeLabel): array
    {
        $problems = [];

        if (!self::isPublishable($publishable)) {
            $problems[] = __('payment_method.setup_publishable_key', ['mode' => $modeLabel]);
        }

        if (!self::isSecret($secret)) {
            $problems[] = __('payment_method.setup_secret_key', ['mode' => $modeLabel]);
        }

        if ($problems === [] && StripeKeysMatchPaymentMode::modeOfKey($publishable) !== StripeKeysMatchPaymentMode::modeOfKey($secret)) {
            $problems[] = __('payment_method.setup_keys_mixed', ['mode' => $modeLabel]);
        }

        return $problems;
    }
}
