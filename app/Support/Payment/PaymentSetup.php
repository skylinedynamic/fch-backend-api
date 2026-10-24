<?php

namespace App\Support\Payment;

use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;

/**
 * Whether a partner's payment method is set up for its active mode.
 *
 * A method switched on but not set up used to be offered to payers anyway:
 * Stripe without a usable publishable key left the card form waiting forever
 * for Stripe.js, and Bank Transfer without a bank account had nothing to pay
 * into. The payer's list (PaymentMethodService::getActiveProvidersForPartner)
 * now leaves such a method out, and the partner's settings list shows these
 * same messages - one rule, so the two can't disagree.
 *
 * Only what can be known without asking the gateway is checked. Stripe itself
 * is asked when the keys are saved (StripeKeyVerifier).
 *
 * The other card gateways (Square, Authorize.Net, Ecrypt...) are paid outside
 * the app with a reference number, so missing credentials don't stop a payer
 * using them - they stay offered. The partner is still told what's missing
 * (issues()), just not that payers can't use it (problems()).
 */
final class PaymentSetup
{
    /** The credentials each gateway's settings form saves per mode; anything unlisted uses the default pair. */
    private const CREDENTIALS = [
        PaymentProvider::SQUARE => ['app_id', 'access_token'],
        PaymentProvider::AUTHORIZE_NET => ['login_id', 'transaction_key'],
    ];
    private const DEFAULT_CREDENTIALS = ['client_key', 'client_secret'];

    /**
     * Flat names those credentials were saved under before the per-mode split
     * (suffixed with the mode). Read, never written - the settings form reads them too.
     */
    private const LEGACY_CREDENTIAL_NAMES = [
        'app_id' => 'square_app_id_',
        'access_token' => 'square_token_',
        'login_id' => 'authorize_login_id_',
        'transaction_key' => 'authorize_transaction_key_',
        'client_key' => 'client_key_',
        'client_secret' => 'client_secret_',
    ];

    /** Methods with nothing to configure, or configured another way. */
    private const NO_CREDENTIALS = [PaymentProvider::STRIPE, PaymentProvider::BANK_TRANSFER, PaymentProvider::CASH];

    /**
     * What the method still needs, as messages for the partner; none when it can take payments.
     *
     * Providers paid outside the app (cash, and the manually reconciled card
     * gateways) need nothing.
     *
     * @return list<string>
     */
    public static function problems(?PartnerPaymentProvider $linkage, ?string $providerName): array
    {
        if (!$linkage) {
            return [];
        }

        $mode = $linkage->paymentMode();
        $modeLabel = self::modeLabel($mode);

        return match ($providerName) {
            PaymentProvider::STRIPE => StripeKeys::problems($linkage->publishableKey(), $linkage->secretKey(), $modeLabel),
            PaymentProvider::BANK_TRANSFER => PaymentMethodBankDetail::availableFor($linkage->partner_id, $mode)->exists()
                ? []
                : [__('payment_method.setup_bank_account', ['mode' => $modeLabel])],
            default => [],
        };
    }

    public static function isReady(?PartnerPaymentProvider $linkage, ?string $providerName): bool
    {
        return $linkage !== null && self::problems($linkage, $providerName) === [];
    }

    /**
     * Everything the partner should be told the method is missing: what stops
     * payers using it (problems()), and for the gateways paid by reference
     * number, any credential left empty for the active mode.
     *
     * @return list<string>
     */
    public static function issues(?PartnerPaymentProvider $linkage, ?string $providerName): array
    {
        if (!$linkage) {
            return [];
        }

        if (in_array($providerName, self::NO_CREDENTIALS, true)) {
            return self::problems($linkage, $providerName);
        }

        $mode = $linkage->paymentMode();
        $config = $linkage->configurationArray();
        $block = PaymentCredentials::forMode($config, $mode);

        $missing = array_filter(
            self::CREDENTIALS[$providerName] ?? self::DEFAULT_CREDENTIALS,
            fn (string $field) => blank($block[$field] ?? null)
                && blank($config[self::LEGACY_CREDENTIAL_NAMES[$field] . $mode] ?? null)
        );

        return array_values(array_map(fn (string $field) => __('payment_method.setup_credential', [
            'mode' => self::modeLabel($mode),
            'field' => __('payment_method.credential.' . $field),
        ]), $missing));
    }

    /** "Sandbox" or "Production", as the settings screen names the modes. */
    public static function modeLabel(string $mode): string
    {
        return __('payment_method.mode_' . PaymentCredentials::normalizeMode($mode));
    }
}
