<?php

namespace App\Http\Controllers\Api\V1\Global\Branding;

use App\Http\Controllers\Controller;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Support\Branding\BrandVars;
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
     * Resolve the tenant for a public request.
     *
     * A <link rel="stylesheet"> sends no Origin header, and the Host is the API's
     * own, so the caller passes its hostname explicitly in `d`. Origin/Host stay
     * as the fallback for callers that do send them.
     */
    private function resolvePartner(Request $request): ?Partner
    {
        $domain = (string) $request->query('d', '');

        if ($domain === '') {
            $origin = $request->header('Origin');
            $domain = ($origin && parse_url($origin, PHP_URL_HOST))
                ? parse_url($origin, PHP_URL_HOST)
                : $request->getHost();
        }

        $domain = preg_replace('/^www\./', '', strtolower(trim($domain)));

        // Cached: this runs on every cold page load, including logged-out ones.
        $partnerId = Cache::remember("domain_partner_id:{$domain}", now()->addHour(), function () use ($domain) {
            $partner = Partner::where('partner_domain', $domain)
                ->orWhere('partner_subdomain', $domain)
                ->orWhere('borrower_domain', $domain)
                ->first();

            return $partner?->id ?? Partner::where('is_host', true)->value('id');
        });

        if (!$partnerId) {
            return null;
        }

        $partner = Partner::find($partnerId);

        // Same rule the rest of the app uses: only a host or a white-label tenant
        // is branded as itself. Everyone else is branded as the host.
        if ($partner && !$partner->is_host && $partner->partner_type !== Partner::PARTNER_TYPE_WHITE_LABEL) {
            $partner = Partner::host();
        }

        return $partner;
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
            ? $this->config->getBrandingSettings($partner->id)
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
     * Branding as JSON, including the precomputed variable map.
     *
     * The portals apply `css_vars` verbatim rather than deriving their own, so
     * the stylesheet above and the running app can never disagree.
     */
    public function show(Request $request)
    {
        $partner = $this->resolvePartner($request);

        $branding = $partner
            ? $this->config->getBrandingSettings($partner->id)
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
