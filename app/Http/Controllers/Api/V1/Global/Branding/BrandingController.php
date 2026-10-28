<?php

namespace App\Http\Controllers\Api\V1\Global\Branding;

use App\Http\Controllers\Controller;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Support\Branding\BrandVars;
use App\Support\Branding\PartnerDomain;
use App\Support\Branding\PortalMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Public branding, served two ways.
 *
 * css()  - a render-blocking stylesheet, so a COLD first visit (nothing in
 *          localStorage yet) paints the tenant's real brand instead of flashing
 *          the packaged default until JS runs.
 * show() - branding as JSON, without the email-config and payment-provider
 *          queries that get-theme also performs, so the portals can paint before
 *          those finish.
 *
 * Both are unauthenticated: the brand is public by nature, and the login screen
 * has to be branded before anyone has a token.
 */
class BrandingController extends Controller
{
    public function __construct(private AppConfigurationService $config)
    {
    }

    /**
     * The portal hostname a public request is about.
     *
     * A <link rel="stylesheet"> sends no Origin header, and the Host is the API's
     * own, so the caller passes its hostname explicitly in `d`. Nginx's SSI
     * include for portal-meta sends it as `X-Portal-Host` instead, so its config
     * can stay free of variables in proxy_pass. Origin/Host stay as the fallback
     * for callers that send neither.
     */
    private function requestedDomain(Request $request): string
    {
        $domain = (string) ($request->query('d') ?: $request->header('X-Portal-Host', ''));

        if ($domain === '') {
            $origin = $request->header('Origin');
            $domain = ($origin && parse_url($origin, PHP_URL_HOST))
                ? parse_url($origin, PHP_URL_HOST)
                : $request->getHost();
        }

        return $domain;
    }

    /**
     * Resolve the tenant for a public request.
     */
    private function resolvePartner(Request $request): ?Partner
    {
        $domain = $this->requestedDomain($request);
        $key = PartnerDomain::partnerIdKey($domain);

        // Cached: this runs on every cold page load, including logged-out ones.
        $partnerId = Cache::remember($key, now()->addHour(), fn () => PartnerDomain::lookup($domain));
        $partner = $partnerId ? Partner::find($partnerId) : null;

        // The cached partner is gone (deleted since): look the domain up again
        // rather than serve an empty brand until the cache expires (BUG-118).
        if (!$partner && $partnerId) {
            Cache::forget($key);
            $partnerId = Cache::remember($key, now()->addHour(), fn () => PartnerDomain::lookup($domain));
            $partner = $partnerId ? Partner::find($partnerId) : null;
        }

        if (!$partner) {
            return null;
        }

        // Same rule the rest of the app uses: only a host or a white-label tenant
        // is branded as itself. Everyone else is branded as the host.
        if ($partner && !$partner->is_host && $partner->partner_type !== Partner::PARTNER_TYPE_WHITE_LABEL) {
            $partner = Partner::host();
        }

        return $partner;
    }

    /**
     * Whether this tenant may show the host's logo when it has none of its own.
     *
     * Only the host may. resolvePartner() has already folded every partner that
     * trades under the host's brand into the host itself, so anything still
     * non-host here is branded as itself - and a login screen showing the host's
     * logo above that tenant's own colours is the mismatch this prevents. Those
     * tenants get a placeholder carrying their company name instead.
     */
    private function inheritsHostLogos(Partner $partner): bool
    {
        return (bool) $partner->is_host;
    }

    /**
     * The tenant's brand as a stylesheet.
     *
     * Linked from index.html, so it is render-blocking by nature: the browser
     * will not paint until it lands. Kept small and cached hard for that reason -
     * a stale brand for a few minutes is a far better failure than a wrong-colour
     * flash on every cold load.
     */
    public function css(Request $request): Response
    {
        $partner = $this->resolvePartner($request);

        $branding = $partner
            ? $this->config->getBrandingSettings($partner->id, $this->inheritsHostLogos($partner))
            : [];

        $css = BrandVars::toCss($branding);
        $etag = '"' . md5($css) . '"';

        // Nothing changed since the browser's copy: skip the body entirely.
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=300',
            ]);
        }

        return response($css, 200)->withHeaders([
            'Content-Type' => 'text/css; charset=UTF-8',
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * The tenant's <title>, description, Open Graph / Twitter tags and favicon,
     * as an HTML fragment for the portal named in `portal` (borrower|partner).
     *
     * Included into index.html by Nginx (SSI) on every page load, so link
     * previews - which never run JavaScript - show the partner's own name and
     * logo. Cached briefly for the same reason as css().
     */
    public function meta(Request $request): Response
    {
        $partner = $this->resolvePartner($request);

        $branding = $partner
            ? $this->config->getBrandingSettings($partner->id, $this->inheritsHostLogos($partner))
            : [];

        $meta = PortalMeta::build($branding, $this->portalFor($request), $this->requestedDomain($request));

        return response()->view('branding.portal-meta', ['meta' => $meta])->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Which portal a portal-meta request is for.
     *
     * The fixed portal hosts name it in `portal`. Custom domains cannot: Caddy
     * hands every one of them to the same Nginx server, which serves either
     * portal, so the domain decides - a partner's borrower_domain is its
     * borrower portal, its partner_domain / partner_subdomain its dashboard.
     */
    private function portalFor(Request $request): string
    {
        $portal = (string) $request->query('portal', '');

        if (in_array($portal, [PortalMeta::PORTAL_BORROWER, PortalMeta::PORTAL_PARTNER], true)) {
            return $portal;
        }

        $domain = PartnerDomain::normalise($this->requestedDomain($request));

        return Cache::remember(PartnerDomain::portalKey($domain), now()->addHour(), function () use ($domain) {
            if (Partner::where('borrower_domain', $domain)->exists()) {
                return PortalMeta::PORTAL_BORROWER;
            }

            return Partner::where('partner_domain', $domain)->orWhere('partner_subdomain', $domain)->exists()
                ? PortalMeta::PORTAL_PARTNER
                : PortalMeta::PORTAL_BORROWER;
        });
    }

    /**
     * Branding as JSON, including the precomputed variable map.
     *
     * The portals apply `css_vars` verbatim rather than deriving their own, so
     * the stylesheet above and the running app can never disagree.
     */
    public function show(Request $request)
    {
        $partner = $this->resolvePartner($request);

        $branding = $partner
            ? $this->config->getBrandingSettings($partner->id, $this->inheritsHostLogos($partner))
            : [];

        return response()->json([
            'success' => true,
            'data' => [
                'branding' => $branding,
                'css_vars' => BrandVars::build($branding),
                'css_vars_version' => BrandVars::VERSION,
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
