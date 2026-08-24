<?php

namespace App\Http\Controllers\Web;

use App\Models\Common\AppConfiguration;
use App\Models\EmbedForm\EmbedFormConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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
            $branding = AppConfiguration::where('partner_id', $partnerId)
                ->where('module', AppConfiguration::MODULE_BRANDING)
                ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo', 'header_logo', 'favicon', 'primary_color', 'secondary_color'])
                ->pluck('value', 'key')
                ->toArray();
        }

        // Resolved site/company name
        $partnerName = $branding['site_name']
            ?? ($partner?->company_name ?: ($partner?->full_name ?: config('app.name', 'Financial Care Hub')));

        // Resolved logo URL (priority: primary_logo -> header_logo -> secondary_logo -> partner->company_logo)
        $logoUrl = null;
        $logoPath = $branding['primary_logo']
            ?? ($branding['header_logo']
            ?? ($branding['secondary_logo']
            ?? $partner?->company_logo));

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
