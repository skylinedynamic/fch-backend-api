<?php

namespace App\Support\EmbedForm;

use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\AppConfigurationService;

/**
 * Whose logo the public application form carries, and which surface's.
 *
 * The form is a lead-capture page under a brand, and which brand depends on what
 * the partner is:
 *
 *   White-Label partner - its own upload, and only its own. The tier exists to
 *                         keep the platform invisible, so inheriting the host's
 *                         mark would advertise the company the partner is paying
 *                         not to show. With nothing uploaded there is no logo.
 *   Certified / host     - the host's upload. A Certified partner trades under
 *                         the host's brand, so the host's mark is correct even
 *                         when the partner has uploaded one of its own.
 *
 * This is deliberately NOT the same rule as getBrandingSettings()' own-first,
 * host-as-fallback default: that answers "what branding does this tenant have",
 * whereas the question here is "whose brand is this page published under".
 */
final class EmbedFormLogo
{
    /**
     * Surfaces in preference order.
     *
     * The form renders on white, so the document logo leads and the sidebar
     * logo - the only one drawn for a dark background - is the last resort.
     * getBrandingSettings() has usually resolved logo_document through its own
     * chain before this is reached; the order is restated here so the form's
     * preference does not depend on that chain staying as it is.
     */
    private const SURFACES = ['logo_document', 'logo_mail', 'logo_sidebar'];

    public function __construct(
        private readonly AppConfigurationService $configurations
    ) {
    }

    /**
     * The logo's path on the public disk, or null when the owning tenant has
     * none - callers skip the image rather than render a broken one.
     */
    public function pathFor(?Partner $partner): ?string
    {
        if ($this->isWhiteLabel($partner)) {
            // inheritLogosFromHost: false keeps the host's mark out of a
            // white-label form. Its company_logo column is the older place the
            // same upload was kept, so it is still honoured as a last resort.
            $branding = $this->configurations->getBrandingSettings($partner->id, false);

            return $this->firstSurface($branding) ?? ($partner->company_logo ?: null);
        }

        return $this->firstSurface(
            $this->configurations->getBrandingSettings(Partner::host()?->id)
        );
    }

    /**
     * NOTE the order. `is_host` is tested first because the host partner is
     * itself stored with `partner_type = 'White-Label Partner (WL)'`, so a
     * type-first branch would send the host down the own-logos-only path - the
     * same trap documented in EmbedFormLocation::forPartner().
     */
    private function isWhiteLabel(?Partner $partner): bool
    {
        return $partner
            && !$partner->is_host
            && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL;
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function firstSurface(array $branding): ?string
    {
        foreach (self::SURFACES as $key) {
            if (!empty($branding[$key])) {
                return $branding[$key];
            }
        }

        return null;
    }
}
