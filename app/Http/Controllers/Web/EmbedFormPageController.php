<?php

namespace App\Http\Controllers\Web;

use App\Models\Borrower\BorrowerDocType;
use App\Models\User\User;
use App\Services\FCH\Common\Scoring\CreditScoreService;
use App\Models\EmbedForm\EmbedFormConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Services\FCH\Common\Setting\AppConfigurationService;

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

        $logoUrl = null;
        // The embed form renders on white, so the document logo is the right one.
        $logoPath = $branding['logo_document']
            ?? ($branding['logo_mail']
            ?? $partner?->company_logo);

        if ($logoPath) {
            $logoUrl = asset('storage/' . $logoPath);
        }

        // Resolved favicon URL
        $faviconUrl = null;
        if (!empty($branding['favicon'])) {
            $faviconUrl = asset('storage/' . $branding['favicon']);
        }

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
            'postalCodeLength' => (int) config('funding_application.embed_form.postal_code_length', 5),
        ]);
    }
}
