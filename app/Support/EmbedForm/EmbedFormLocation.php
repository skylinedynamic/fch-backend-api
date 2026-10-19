<?php

namespace App\Support\EmbedForm;

use App\Models\Partner\Partner;

/**
 * Where a partner's public application form is published, or why it cannot be.
 *
 * The form is a PUBLIC lead-capture page, so the link belongs on the partner's
 * own public-facing address. It used to be built on the API's own host, which
 * showed a prospect the platform's hostname instead of the partner's.
 *
 *   Host / Certified partner - BASE_WEBSITE, the platform's public site.
 *   White-Label partner      - its own `partner_domain`, falling back to
 *                              `partner_subdomain`. No platform fallback: the
 *                              whole point of the tier is that the platform is
 *                              invisible, so with neither column set there is no
 *                              valid address and the form cannot be created.
 *
 * `partner_domain` is used only while `active_custom_url` is on, because
 * DomainResolverController::caddyCheck() approves that column only under that
 * flag - without it Caddy issues no certificate and the domain resolves nowhere,
 * so the link would be dead. `partner_subdomain` is approved unconditionally
 * there, which is what makes it a safe fallback rather than a second guess.
 *
 * The page itself is still rendered by this application, unauthenticated, at
 * `/form/{key}` (routes/web.php). Serving it on these hosts is a proxy rule at
 * the edge, not a second copy of the form.
 *
 * In the `local` environment there is no such edge, so every form links to
 * BASE_API_URL - the only address that opens on a developer's machine. A
 * White-Label partner still needs its domain set there: that is a data rule.
 */
final class EmbedFormLocation
{
    private function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $warning
    ) {
    }

    /**
     * Resolve the form's home for a partner.
     *
     * A null partner is treated as the host: the platform's own form.
     *
     * NOTE the order. `is_host` is tested first because the host partner is
     * stored with `partner_type = 'White-Label Partner (WL)'`, so a type-first
     * branch would send it down the partner-domain path and find no columns set.
     */
    public static function forPartner(?Partner $partner): self
    {
        if ($partner && !$partner->is_host && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL) {
            return self::forWhiteLabel($partner);
        }

        // On a developer's machine nothing proxies /form/* from BASE_WEBSITE,
        // so the link points at BASE_API_URL, where the page is rendered.
        // Only `local`: staging keeps the published address.
        if (app()->environment('local')) {
            return new self(self::apiUrl(), null);
        }

        $baseUrl = self::baseWebsiteUrl();

        // A blank address is not a usable one. Without this an unset
        // BASE_WEBSITE produced an empty base, which is not null - so
        // isAvailable() reported true and the settings screen showed neither a
        // warning nor a link, leaving the partner with no way to tell that
        // anything was wrong.
        if ($baseUrl === '') {
            return new self(null, __('embed_form.base_website_required'));
        }

        return new self($baseUrl, null);
    }

    /**
     * A White-Label partner, which publishes on a domain of its own.
     */
    private static function forWhiteLabel(Partner $partner): self
    {
        $domain = $partner->active_custom_url && !empty($partner->partner_domain)
            ? $partner->partner_domain
            : ($partner->partner_subdomain ?: null);

        if (empty($domain)) {
            return new self(null, __('embed_form.partner_domain_required'));
        }

        // Outside production a tenant domain resolves nowhere, so the link would
        // be untestable. The requirement above is still enforced - it is a data
        // rule, not a routing one - but once satisfied the link points at this
        // application, which is where the page is served from.
        if (app()->environment('local')) {
            return new self(self::apiUrl(), null);
        }

        if (!app()->environment('production')) {
            return new self(self::appUrl(), null);
        }

        return new self(self::normalise($domain), null);
    }

    /**
     * The platform's public website, shared by the host and Certified partners.
     *
     * BASE_WEBSITE in every environment. It used to fall back to this
     * application's own APP_URL outside production, on the grounds that the
     * marketing site's dev server does not serve `/form/*` - but that meant the
     * settings screen showed a `127.0.0.1:8000` link locally, which is not the
     * address the form is actually published on and cannot be shared. The
     * configured website is the answer to "where is this form published", so it
     * is what gets shown; whether that host proxies `/form/*` yet is a routing
     * concern, not a reason to display a different address.
     */
    private static function baseWebsiteUrl(): string
    {
        $configured = trim((string) config('app.base_website'));

        return $configured === '' ? '' : self::normalise($configured);
    }

    private static function appUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** BASE_API_URL: the API that renders /form/{key}, used locally. */
    private static function apiUrl(): string
    {
        return self::normalise((string) config('domains.reserved.base_reserved_api_portal')) ?: self::appUrl();
    }

    /**
     * BASE_WEBSITE and the partner columns are all stored bare
     * (exodias.fourcornerfunding.com), so the scheme is added rather than assumed.
     */
    private static function normalise(?string $domain): string
    {
        return rtrim(concatUrl($domain, ''), '/');
    }

    /** Whether a form can be published for this partner at all. */
    public function isAvailable(): bool
    {
        return $this->baseUrl !== null;
    }

    /** What the partner must configure before a form can exist, if anything. */
    public function warning(): ?string
    {
        return $this->warning;
    }

    public function baseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /** The host the form is served from, for the origin allow-list. */
    public function host(): ?string
    {
        return $this->baseUrl ? parse_url($this->baseUrl, PHP_URL_HOST) : null;
    }

    /** The shareable link, or null when the partner has nowhere to publish. */
    public function formUrl(?string $publicKey): ?string
    {
        return ($this->baseUrl && $publicKey) ? "{$this->baseUrl}/form/{$publicKey}" : null;
    }

    /** The iframe snippet a partner pastes into their own site. */
    public function embedSnippet(?string $publicKey): ?string
    {
        $url = $this->formUrl($publicKey);

        return $url
            ? "<iframe src=\"{$url}\" width=\"100%\" height=\"800\" frameborder=\"0\" style=\"border:none;border-radius:8px;\"></iframe>"
            : null;
    }
}
