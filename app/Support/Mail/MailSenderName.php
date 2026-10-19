<?php

namespace App\Support\Mail;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;

/**
 * The name a partner's mail is sent under - the same whether it goes out
 * through the partner's own settings or the platform fallback, so a borrower
 * sees their partner's name rather than the platform's.
 *
 *   1. The partner's branded site name (Settings > Branding).
 *   2. Its company name on the partner record.
 *   3. The app's name.
 *
 * Mail with no partner is the host's. Only the partner's own branding row is
 * read: falling through to another tenant's would put their name on this
 * partner's mail.
 */
final class MailSenderName
{
    public static function for(?int $partnerId): string
    {
        $partnerId ??= Partner::where('is_host', true)->value('id');

        if ($partnerId !== null) {
            $siteName = self::text(AppConfiguration::where('partner_id', $partnerId)
                ->where('module', AppConfiguration::MODULE_BRANDING)
                ->where('key', 'site_name')
                ->value('value'));

            if ($siteName !== null) {
                return $siteName;
            }

            $companyName = self::text(Partner::whereKey($partnerId)->value('company_name'));

            if ($companyName !== null) {
                return $companyName;
            }
        }

        return (string) config('app.name');
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return ($value === '' || $value === 'null') ? null : $value;
    }
}
