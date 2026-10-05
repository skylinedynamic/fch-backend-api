<?php

namespace App\Rules;

use App\Models\PaymentMethod\PaymentProvider;
use App\Support\Payment\PaymentCredentials;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reject a Stripe configuration whose keys belong to the other mode.
 *
 * Stripe key prefixes state their own mode - `pk_test_`/`sk_test_` against the
 * test account, `pk_live_`/`sk_live_` against the live one - so a production
 * block holding test keys is detectable the moment it is submitted.
 *
 * Catching it here matters because the alternative place to find out is a
 * declined payment. A mode mismatch produces "No such PaymentMethod" from
 * Stripe, which names a token rather than a setting and reads like a Connect
 * problem, so the configuration screen is the last place anyone looks.
 *
 * Only the block being saved for each mode is checked. A partner legitimately
 * keeps test keys in the sandbox block while running production, so this
 * compares each block against its own mode, not against the active one.
 */
class StripeKeysMatchPaymentMode implements ValidationRule
{
    /**
     * @param string|null $providerName Provider this configuration belongs to.
     */
    public function __construct(private readonly ?string $providerName)
    {
    }

    /**
     * Mode a Stripe key declares through its prefix, or null if it declares none.
     *
     * Restricted keys (`rk_live_`, `rk_test_`) are real secret keys and are
     * checked too. Anything else - a placeholder, a masked value, an empty
     * string - is left alone, since this rule is about contradiction, not
     * completeness.
     */
    public static function modeOfKey(mixed $key): ?string
    {
        if (!is_string($key) || $key === '') {
            return null;
        }

        foreach ([PaymentProvider::MODE_PRODUCTION => 'live', PaymentProvider::MODE_SANDBOX => 'test'] as $mode => $marker) {
            foreach (['pk_', 'sk_', 'rk_'] as $prefix) {
                if (str_starts_with($key, $prefix . $marker . '_')) {
                    return $mode;
                }
            }
        }

        return null;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->providerName !== PaymentProvider::STRIPE) {
            return;
        }

        $config = PaymentCredentials::toArray($value);

        foreach ([PaymentProvider::MODE_SANDBOX, PaymentProvider::MODE_PRODUCTION] as $mode) {
            if (!isset($config[$mode]) || !is_array($config[$mode])) {
                continue;
            }

            foreach (['publishable_key', 'secret_key'] as $field) {
                $keyMode = self::modeOfKey($config[$mode][$field] ?? null);

                if ($keyMode !== null && $keyMode !== $mode) {
                    $fail(__('payment_method.stripe_key_mode_mismatch', [
                        'field' => $field,
                        'mode' => $mode,
                        'key_mode' => $keyMode,
                    ]));

                    return;
                }
            }
        }
    }
}
