<?php

namespace App\Services\Global\Common;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;

/**
 * The portal a login's links open on.
 *
 * - A client (borrower) uses the borrower portal: its White-Label partner's
 *   own borrower domain when that partner has the custom URL switched on,
 *   otherwise the shared borrower portal (BASE_BORROWER_PORTAL).
 * - Everyone else uses the partner portal: a White-Label partner's custom
 *   domain when switched on, else its subdomain; a Certified partner's
 *   subdomain; the shared partner portal (BASE_PARTNER_PORTAL) for the host
 *   and for any partner without a subdomain.
 * - Locally partner domains don't resolve, so every link goes to the env
 *   portals.
 *
 * A custom domain only counts with active_custom_url on - the same rule
 * DomainResolverController serves the portals by.
 */
class PortalUrlResolver
{
    /** A full URL to $path on the login's portal. */
    public function url(User $user, string $path): string
    {
        return concatUrl($this->domain($user), $path);
    }

    private function domain(User $user): string
    {
        // The record behind the login decides, not its user_type.
        $isBorrower = $user->entity_type === Borrower::class;
        $fallback = (string) config($isBorrower ? 'app.frontend_borrower_url' : 'app.frontend_partner_url');

        $partner = $user->partner;
        if (app()->isLocal() || !$partner || $partner->is_host) {
            return $fallback;
        }

        $isWhiteLabel = $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL;
        $customDomain = fn (?string $domain) => $isWhiteLabel && $partner->active_custom_url && filled($domain) ? $domain : null;

        if ($isBorrower) {
            return $customDomain($partner->borrower_domain) ?? $fallback;
        }

        return $customDomain($partner->partner_domain)
            ?? (filled($partner->partner_subdomain) ? $partner->partner_subdomain : $fallback);
    }
}
