<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\Setting\MailConfiguration;

/**
 * Which mail settings a partner's mail is sent with.
 *
 * Credentials are DATABASE-ONLY. config/mail.php carries no SMTP or SES
 * credentials from the environment, so there is no silent .env fallback
 * behind this: whatever this returns is what gets used, and null means the
 * mail is not sent at all.
 *
 * In order:
 *
 *   1. The partner's default provider - once verified. A configuration is only
 *      trusted after a code sent through it came back, and only for the exact
 *      settings that sent it (see MailConfiguration::isVerified()).
 *   2. The platform-wide fallback, kept by the host partner - its verified
 *      default, or failing that any of its providers that is verified: it is
 *      the last resort, so it would rather send through a verified SMTP than
 *      nothing at all. Every partner without a verified default sends
 *      through it, the host included.
 *
 * Managing the settings themselves is MailConfigurationService.
 */
class EmailConfigurationService
{
    public function resolveFor(int|string|null $partnerId): ?\stdClass
    {
        return $this->configurationFor($partnerId)?->toMailSettings();
    }

    public function configurationFor(int|string|null $partnerId): ?MailConfiguration
    {
        $own = $this->ownDefault($partnerId);
        if ($own && $this->canSend($own)) {
            return $own;
        }

        return $this->fallbackConfiguration();
    }

    /**
     * The fallback configuration mail goes out with, if any can send: the
     * default first, then the others in provider order.
     */
    public function fallbackConfiguration(): ?MailConfiguration
    {
        $order = array_flip(array_keys(MailConfiguration::providers()));

        return MailConfiguration::fallback()->get()
            ->sortBy(fn (MailConfiguration $config) => [$config->is_default ? 0 : 1, $order[$config->provider] ?? PHP_INT_MAX])
            ->first(fn (MailConfiguration $config) => $this->canSend($config));
    }

    /**
     * Whether this partner's mail goes out under its own settings rather than
     * the fallback.
     */
    public function usesOwnConfiguration(int|string|null $partnerId): bool
    {
        $own = $this->ownDefault($partnerId);

        return $own !== null && $this->canSend($own);
    }

    /**
     * Whether this partner has saved any mail settings of its own, verified or
     * not - what the "finish your setup" guard asks.
     */
    public function hasAnyConfiguration(int|string|null $partnerId): bool
    {
        return $partnerId !== null && $partnerId !== ''
            && MailConfiguration::forPartner((int) $partnerId)->exists();
    }

    /**
     * Whether resolved settings carry enough to actually connect: an address
     * and an identity for SMTP, a key pair for SES.
     */
    public function isUsable(?\stdClass $config): bool
    {
        if (!$config) {
            return false;
        }

        $provider = $config->email_provider ?? MailConfiguration::PROVIDER_SMTP;

        foreach (self::CONNECT_FIELDS[$provider] ?? ['unknown'] as $key) {
            if (empty($config->{$key})) {
                return false;
            }
        }

        return true;
    }

    /** The settings without which a provider cannot even connect. */
    private const CONNECT_FIELDS = [
        MailConfiguration::PROVIDER_SMTP => ['smtp_host', 'smtp_username'],
        MailConfiguration::PROVIDER_SES => ['aws_access_key', 'aws_secret_key'],
    ];

    private function ownDefault(int|string|null $partnerId): ?MailConfiguration
    {
        if ($partnerId === null || $partnerId === '') {
            return null;
        }

        return MailConfiguration::forPartner((int) $partnerId)->where('is_default', true)->first();
    }

    private function canSend(MailConfiguration $config): bool
    {
        return $config->isUsable() && $config->isVerified();
    }
}
