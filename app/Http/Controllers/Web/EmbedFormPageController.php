<?php

namespace App\Http\Controllers\Web;

use App\Models\Borrower\BorrowerDocType;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Scoring\CreditScoreService;
use App\Models\EmbedForm\EmbedFormConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Support\EmbedForm\EmbedFormLogo;
use App\Rules\UsPostalCode;
use Illuminate\Support\Facades\Storage;

class EmbedFormPageController extends Controller
{
    public function show(Request $request, string $key)
    {
        $config = EmbedFormConfig::with('partner')
            ->where('public_key', $key)
            ->where('is_active', true)
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

        // Resolved site/company name
        $partnerName = $branding['site_name']
            ?? ($partner?->company_name ?: ($partner?->full_name ?: config('app.name', 'Financial Care Hub')));

        $logoUrl = $this->resolveLogoUrl($branding, $partner);
        $faviconUrl = $this->resolveFaviconUrl($branding);

        // Theme colors
        $primaryColor = $branding['primary_color'] ?? '#0d6efd';
        $secondaryColor = $branding['secondary_color'] ?? '#198754';

        return view('embed-form.index', [
            'config' => $config,
            'partnerName' => $partnerName,
            'logoUrl' => $logoUrl,
            'faviconUrl' => $faviconUrl,
            'primaryColor' => $primaryColor,
            'secondaryColor' => $secondaryColor,
            'publicKey' => $key,
            'apiBase' => config('app.url') . '/api',

            // The funding application's own dropdowns and document buckets, so
            // the public form asks for exactly what the portal's wizard does.
            'options' => config('funding_application.options'),
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
            'minimumAge' => (int) config('funding_application.embed_form.minimum_age', 18),
            'postalCodeLength' => UsPostalCode::length(),
        ]);
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
    private function resolveLogoUrl(array $branding, ?Partner $partner): ?string
    {
        $resolved = ($branding['logo_document_full_url'] ?? null)
            ?: ($branding['logo_mail_full_url'] ?? null);

        if ($resolved) {
            return $resolved;
        }

        return $partner?->company_logo
            ? encodeUrl(asset('storage/' . ltrim($partner->company_logo, '/')))
            : null;
    }

    /**
     * The favicon for the tab, or null to leave the tag out.
     *
     * Read from the built address for the same reason as the logo above.
     */
    private function resolveFaviconUrl(array $branding): ?string
    {
        return ($branding['favicon_full_url'] ?? null) ?: null;
    }
}
