<?php

namespace App\Support\Payment;

use App\Models\PaymentMethod\PaymentProvider;

/**
 * Which set of gateway credentials a partner's payment configuration is using.
 *
 * A partner-provider linkage stores two credential sets side by side - one for
 * `sandbox`, one for `production` - and a `payment_mode` column that says which
 * of them is live. Resolving a charge means answering the same two questions
 * every time: what mode is this linkage in, and which keys does that mode use.
 *
 * Both answers used to be open-coded at every call site, with four different
 * fallback chains between them. One of those chains read `payment_mode` out of
 * the encrypted `configuration` JSON - where PaymentMethodConfigurationService
 * deliberately unsets it before saving, because the column is the record. That
 * read therefore always missed and always defaulted to `sandbox`, and because it
 * sat on PaymentGatewayManager::driverFor() it put every real charge on the test
 * secret key while the publishable key handed to the browser - resolved by one of
 * the correct chains - stayed live. Cards tokenized against the live account,
 * charges ran against the test one, and Stripe answered "No such PaymentMethod"
 * for a token it had genuinely never seen.
 *
 * So the point of this class is not brevity. It is that mode and credentials are
 * resolved in exactly one place, and cannot drift apart between the half of a
 * payment that runs in the browser and the half that runs on the server.
 *
 * The flat-config fallbacks below are legacy: configurations written before the
 * sandbox/production split stored their keys at the top level, some under
 * provider-prefixed names. They are read, never written.
 */
final class PaymentCredentials
{
    /**
     * Normalize a stored mode into one of the two modes that exist.
     *
     * Anything unrecognized - null, an empty column, a typo, a legacy value -
     * resolves to sandbox. Defaulting the other way would let a bad value move
     * real money.
     */
    public static function normalizeMode(mixed $mode): string
    {
        return strtolower(trim((string) $mode)) === PaymentProvider::MODE_PRODUCTION
            ? PaymentProvider::MODE_PRODUCTION
            : PaymentProvider::MODE_SANDBOX;
    }

    /**
     * The mode a linkage is in, given its column and its configuration.
     *
     * The column is the source of truth. The JSON is consulted only for rows
     * written before the column existed, which still carry the key inline.
     */
    public static function mode(mixed $columnMode, mixed $configuration = []): string
    {
        $config = self::toArray($configuration);

        $mode = $columnMode !== null && trim((string) $columnMode) !== ''
            ? $columnMode
            : ($config['payment_mode'] ?? null);

        return self::normalizeMode($mode);
    }

    /**
     * Coerce a stored configuration into an array.
     *
     * The column is cast `encrypted:array`, but callers have historically seen
     * stdClass and null here too, so every entry point normalizes.
     */
    public static function toArray(mixed $configuration): array
    {
        if (is_object($configuration)) {
            $configuration = (array) $configuration;
        }

        return is_array($configuration) ? $configuration : [];
    }

    /**
     * Whether a configuration separates its credentials by mode.
     *
     * A configuration that does is authoritative about which keys belong to
     * which account, and its bare top-level keys - if any survive from before
     * the split - must not be read as belonging to either.
     */
    public static function isModeSeparated(mixed $configuration): bool
    {
        $config = self::toArray($configuration);

        return (isset($config[PaymentProvider::MODE_SANDBOX]) && is_array($config[PaymentProvider::MODE_SANDBOX]))
            || (isset($config[PaymentProvider::MODE_PRODUCTION]) && is_array($config[PaymentProvider::MODE_PRODUCTION]));
    }

    /**
     * The credential block for one mode.
     *
     * Falls back to the whole configuration for flat legacy rows, which have no
     * per-mode nesting to select.
     */
    public static function forMode(mixed $configuration, string $mode): array
    {
        $config = self::toArray($configuration);
        $mode = self::normalizeMode($mode);

        return isset($config[$mode]) && is_array($config[$mode])
            ? $config[$mode]
            : $config;
    }

    /**
     * The secret key for one mode, or null when that mode is not configured.
     *
     * Null is a meaningful answer: it means this partner cannot transact in this
     * mode, and the caller should say so rather than reach for the other mode's
     * key.
     */
    public static function secretKey(mixed $configuration, string $mode): ?string
    {
        $config = self::toArray($configuration);
        $mode = self::normalizeMode($mode);

        // A bare `secret_key` says nothing about which account it belongs to. Once
        // a configuration is mode-separated it must not be consulted, or a mode
        // whose own block is half-filled silently borrows the other one's key -
        // the same cross-account mix-up this class exists to prevent. The
        // mode-suffixed legacy name is unambiguous and stays.
        return self::forMode($config, $mode)['secret_key']
            ?? (self::isModeSeparated($config) ? null : ($config['secret_key'] ?? null))
            ?? $config['stripe_secret_' . $mode]
            ?? null;
    }

    /**
     * The publishable key for one mode, or null when that mode is not configured.
     */
    public static function publishableKey(mixed $configuration, string $mode): ?string
    {
        $config = self::toArray($configuration);
        $mode = self::normalizeMode($mode);
        $envConfig = self::forMode($config, $mode);

        $key = $envConfig['publishable_key']
            ?? $envConfig['public_key']
            ?? (self::isModeSeparated($config) ? null : ($config['publishable_key'] ?? $config['public_key'] ?? null))
            ?? $config['stripe_key_' . $mode]
            ?? null;

        // This value is sent to the browser. A secret or restricted key saved in
        // the publishable field (keys swapped) must never leave the server.
        if (is_string($key) && preg_match('/^(sk|rk)_(test|live)_/', $key)) {
            return null;
        }

        return $key;
    }
}
