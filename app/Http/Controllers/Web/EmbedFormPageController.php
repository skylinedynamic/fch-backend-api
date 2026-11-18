<?php

namespace App\Http\Controllers\Web;

use App\Models\Borrower\BorrowerDocType;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use App\Services\Global\General\LenderProductCategoryService;
use App\Services\FCH\Common\Scoring\CreditScoreService;
use App\Models\EmbedForm\EmbedFormConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Support\EmbedForm\EmbedFormDisplay;
use App\Support\EmbedForm\EmbedFormLogo;
use App\Rules\DateOfBirth;
use App\Rules\UsPostalCode;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;

class EmbedFormPageController extends Controller
{
    /**
     * Surfaces in preference order. The form renders on white, so the document
     * logo leads and the sidebar logo - the only one drawn for a dark background
     * - is the last resort.
     */
    private const LOGO_SURFACES = ['logo_document', 'logo_mail', 'logo_sidebar'];

    public function show(Request $request, string $key)
    {
        // Looked up by key alone: a form switched off is paused, not gone, and
        // says so below (R3-BUG-014). Only a key no form has is a 404.
        $config = EmbedFormConfig::with('partner')
            ->where('public_key', $key)
            ->first();

        if (!$config) {
            abort(404, 'This application form is not available.');
        }

        $partner = $config->partner;
        $partnerId = $partner?->id;

        // Retrieve branding configurations from app_configurations table
        $branding = [];
        if ($partnerId) {
            // getBrandingSettings() resolves each surface through its fallback
            // chain and inherits from the host, which reading raw rows did not -
            // a partner with only some logos uploaded got no logo at all here.
            $branding = app(AppConfigurationService::class)->getBrandingSettings($partnerId);
        }

        // Who the applicant is dealing with: this partner's own site name, then
        // its company name. Never the host's site name - see ownSiteName().
        $partnerName = ($partnerId ? app(AppConfigurationService::class)->ownSiteName($partnerId) : null)
            ?: ($partner?->company_name ?: ($partner?->full_name ?: config('app.name', 'Financial Care Hub')));

        // Whose logo this is - the partner's own or the host's - is a
        // brand-ownership rule, so it is resolved before the address is built.
        // $branding above is still this partner's own, for name and colours.
        $logoBranding = app(EmbedFormLogo::class)->brandingFor($partner);

        $logoUrl = $this->resolveLogoUrl($logoBranding, $partner);
        $faviconUrl = $this->resolveFaviconUrl($branding);

        // Header, footer, background, frame and primary colour, from the embed
        // code's link.
        $display = EmbedFormDisplay::fromRequest($request);

        // Theme colors. The embed code's own primary colour, when it has one,
        // matches the form to the site around it.
        $primaryColor = $display->primaryColor() ?? $branding['primary_color'] ?? '#0d6efd';
        $secondaryColor = $branding['secondary_color'] ?? '#198754';

        // Switched off: the partner's name and logo and a short message, nothing
        // to fill in. Framed by the same rule, so it shows inside the iframe too.
        if (!$config->is_active) {
            return $this->framed(response()->view('embed-form.inactive', [
                'config' => $config,
                'partnerName' => $partnerName,
                'logoUrl' => $logoUrl,
                'faviconUrl' => $faviconUrl,
                'primaryColor' => $primaryColor,
            ]), $config);
        }

        $response = response()->view('embed-form.index', [
            'config' => $config,
            'partnerName' => $partnerName,
            'logoUrl' => $logoUrl,
            'faviconUrl' => $faviconUrl,
            'primaryColor' => $primaryColor,
            'secondaryColor' => $secondaryColor,
            'publicKey' => $key,
            'display' => $display,
            'apiBase' => config('app.url') . '/api',

            // The funding application's own dropdowns and document buckets, so
            // the public form asks for exactly what the portal's wizard does.
            // Funding types: the same service as GET /api/v1/common/funding-types.
            // Purpose of Funds comes straight from the config, as before.
            'options' => array_merge(config('funding_application.options'), [
                'funding_types' => app(LenderProductCategoryService::class)->fundingTypeNames(),
            ]),
            // Code => symbol; the first is preselected.
            'currencies' => config('funding_application.embed_form.currencies'),
            'locations' => config('locations'),
            'docTypes' => BorrowerDocType::query()
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->toArray(),

            // Sales reps are picked from this partner's own active users, never
            // typed, and never sourced from another tenant.
            'salesReps' => User::salesRepresentatives($partnerId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray(),

            'referralSources' => config('funding_application.embed_form.referral_sources', []),
            'referralSourceOther' => config('funding_application.embed_form.referral_source_other', 'Other'),
            'creditScore' => app(CreditScoreService::class)->toArray(),
            'minimumAge' => DateOfBirth::minimumAge(),
            // The calendar's last pickable date of birth, worked out the same
            // way the API checks it (no rolling 29 Feb into March).
            'latestDateOfBirth' => DateOfBirth::latestAllowedDate()->toDateString(),
            'postalCodeLength' => UsPostalCode::length(),
        ]);

        return $this->framed($response, $config);
    }

    /**
     * Only the Allowed Domains may show this page in an iframe; the browser
     * enforces it (BUG-092). No list: unrestricted.
     */
    private function framed(Response $response, EmbedFormConfig $config): Response
    {
        $frameAncestors = app(EmbedFormService::class)->frameAncestors($config);
        if ($frameAncestors) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors {$frameAncestors}");
        }

        return $response;
    }

    /**
     * The logo for this form's header, or null when there is none to draw.
     *
     * Branding hands out every logo twice: the bare key holds the stored path,
     * and `<key>_full_url` holds the address already built from it - run through
     * encodeUrl() and with any leading slash stripped. The second one is what
     * every other surface in the application reads, and what is read here.
     *
     * This used to take the bare path and rebuild the address with
     * `asset('storage/'.$path)`, which skipped both of those steps. A logo
     * uploaded as "my logo.png" produced an unescaped src the browser could not
     * load, and a path stored with a leading slash produced `storage//logo.png`.
     * Either way the header showed a broken image. Rebuilding also discarded the
     * placeholder branding returns for a tenant with no upload of its own, since
     * the placeholder is not a stored file and so never appears in the bare key.
     *
     * The form renders on white, so the document logo is the right surface, with
     * the mail logo behind it. `company_logo` is the legacy partner column, kept
     * last for partners whose logo was never moved into branding.
     */
    /**
     * Whose logo, and on which host.
     *
     * Not the `*_full_url` keys from getBrandingSettings(): those resolve
     * own-logo-first for every partner type, which shows a Certified partner its
     * own mark instead of the host's, and they are built with asset() - so the URL
     * carries whichever host served the request, then gets cached for six hours
     * and handed to every other host. This page is published on the partner's
     * domain by a proxy while /storage is served by the API, so both parts matter:
     * EmbedFormLogo decides whose logo it is, and the public disk fixes the host.
     */
    private function resolveLogoUrl(array $branding, ?Partner $partner): ?string
    {
        foreach (self::LOGO_SURFACES as $key) {
            // The stored path first, so the address is built on the API's host.
            if (!empty($branding[$key])) {
                return $this->publicUrl($branding[$key]);
            }

            // Then whatever branding built itself. With no stored file this is
            // the tenant's placeholder logo, which has no path to rebuild from -
            // reading only the bare key threw it away and the header fell back to
            // a single letter.
            if (!empty($branding[$key . '_full_url'])) {
                return $branding[$key . '_full_url'];
            }
        }

        return $partner?->company_logo
            ? $this->publicUrl($partner->company_logo)
            : null;
    }

    /**
     * A stored path as an address on the host that serves /storage.
     *
     * encodeUrl() and the leading-slash trim match what branding's own
     * `<key>_full_url` does - a logo saved as "my logo.png" is otherwise an src
     * the browser cannot load, and a path stored with a slash gives `storage//`.
     * The public disk replaces asset(): this page is served on the partner's
     * domain by a proxy, and asset() would build on that host instead of the
     * API's, where the file actually is.
     */
    private function publicUrl(string $path): string
    {
        $path = ltrim($path, '/');
        $url = Storage::disk(Disk::UPLOADS)->url($path);

        // BASE_API_URL unset leaves the disk's base as the bare string
        // `/storage`, so the disk hands back a host-less address. A relative src
        // resolves against whichever host served the page - the partner's domain,
        // which does not serve /storage - so it renders a broken image every time.
        // asset() is only the fallback because it follows the request host, and
        // that is the API's own only where the edge proxy rewrites Host for
        // /form/*; as a last resort it still beats an address that cannot resolve.
        if (!preg_match('#^https?://#i', $url)) {
            $url = asset('storage/' . $path);
        }

        return encodeUrl($url);
    }

    /**
     * The favicon for the tab, or null to leave the tag out.
     *
     * Read from the built address for the same reason as the logo above.
     */
    /**
     * Same host problem as the logo above, so the same answer: the bare key
     * through the public disk rather than the asset()-built `_full_url`.
     */
    private function resolveFaviconUrl(array $branding): ?string
    {
        $favicon = $branding['favicon'] ?? null;

        return $favicon
            ? encodeUrl(Storage::disk(Disk::UPLOADS)->url($favicon))
            : null;
    }
}
