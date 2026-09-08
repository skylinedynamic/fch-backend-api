<?php

namespace App\Http\Controllers\Web;

use App\Models\Common\AppConfiguration;
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
        ]);
    }
}
