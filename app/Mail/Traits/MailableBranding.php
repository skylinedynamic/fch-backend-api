<?php

namespace App\Mail\Traits;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Log;

trait MailableBranding
{
    /**
     * Resolve branding data (site name and logo URL) dynamically for this mailable.
     * Only falls back to default app config and logo if the receiver is the Host.
     */
    protected function getBrandingData(?int $explicitPartnerId = null): array
    {
        $partnerId = $explicitPartnerId
            ?? ($this->mailPartnerId ?? null)
            ?? ($this->dynamicData['partner_id'] ?? null)
            ?? ($this->mailData['partner_id'] ?? null)
            ?? ($this->paymentData['partner_id'] ?? null)
            ?? (function_exists('getConfigPartner') && getConfigPartner() ? getConfigPartner()->id : null);

        $partner = $partnerId ? Partner::find($partnerId) : Partner::where('is_host', true)->first();
        if (!$partner) {
            $partner = Partner::where('is_host', 1)->first() ?: Partner::first();
        }

        $resolvedPartnerId = $partner ? $partner->id : null;
        $isHost = (bool) ($partner?->is_host ?? false);

        $brandingConfig = $resolvedPartnerId ? AppConfiguration::where('partner_id', $resolvedPartnerId)
            ->where('module', AppConfiguration::MODULE_BRANDING)
            ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo', 'header_logo'])
            ->pluck('value', 'key')
            ->toArray() : [];

        $siteName = $brandingConfig['site_name']
            ?? $partner?->company_name
            ?? ($isHost ? config('app.name') : ($partner?->name ?? ''));

        $rawLogo = $brandingConfig['secondary_logo']
            ?? $brandingConfig['primary_logo']
            ?? $brandingConfig['header_logo']
            ?? $partner?->company_logo
            ?? $partner?->partner_image;

        $logoUrl = null;
        if ($rawLogo && $rawLogo !== 'null') {
            if (str_starts_with($rawLogo, 'http://') || str_starts_with($rawLogo, 'https://')) {
                $logoUrl = encodeUrl($rawLogo);
            } else {
                $cleanPath = ltrim((string) $rawLogo, '/');
                if (!str_starts_with($cleanPath, 'site-settings/') && !str_starts_with($cleanPath, 'partners/') && !str_starts_with($cleanPath, 'storage/')) {
                    $cleanPath = ($partner && $partner->company_logo === $rawLogo)
                        ? "partners/{$resolvedPartnerId}/{$cleanPath}"
                        : "site-settings/{$cleanPath}";
                }
                $cleanPath = preg_replace('/^storage\//', '', $cleanPath);
                $logoUrl = asset('storage/' . $cleanPath);
            }
        }

        // Only fall back to default app logo assets when the receiver is the Host
        if (!$logoUrl && $isHost) {
            if (file_exists(public_path('logo.png'))) {
                $logoUrl = asset('logo.png');
            } elseif (file_exists(public_path('assets/images/logo.png'))) {
                $logoUrl = asset('assets/images/logo.png');
            } elseif (file_exists(public_path('logo-mail.png'))) {
                $logoUrl = asset('logo-mail.png');
            }
        }

        return [
            'siteName' => $siteName,
            'logoUrl' => $logoUrl,
        ];
    }

    /**
     * Resolve branding specifically for host partner.
     */
    protected function getHostBrandingData(): array
    {
        $hostPartner = Partner::where('is_host', true)->first();
        return $this->getBrandingData($hostPartner?->id);
    }
}
