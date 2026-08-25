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
            ?? ($this->mailData['user']?->partner_id ?? null)
            ?? ($this->paymentData['partner_id'] ?? null)
            ?? (function_exists('getConfigPartner') && getConfigPartner() ? getConfigPartner()->id : null);

        $partner = $partnerId ? Partner::find($partnerId) : Partner::where('is_host', true)->first();
        if (!$partner) {
            $partner = Partner::where('is_host', 1)->first() ?: Partner::first();
        }

        $resolvedPartnerId = $partner ? $partner->id : null;
        $isHost = (bool) ($partner?->is_host ?? false);

        $brandingConfig = [];
        if ($resolvedPartnerId) {
            $configs = AppConfiguration::where('partner_id', $resolvedPartnerId)
                ->where('module', AppConfiguration::MODULE_BRANDING)
                ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo'])
                ->get();

            foreach ($configs as $cfg) {
                $val = trim((string) $cfg->value);
                if ($val !== '' && $val !== 'null') {
                    $brandingConfig[$cfg->key] = $val;
                }
            }
        }

        // Host fallback configurations if needed
        $hostPartner = $isHost ? $partner : (Partner::where('is_host', true)->first() ?: Partner::where('is_host', 1)->first());
        $hostBrandingConfig = [];
        if ($hostPartner && $hostPartner->id !== $resolvedPartnerId) {
            $hostConfigs = AppConfiguration::where('partner_id', $hostPartner->id)
                ->where('module', AppConfiguration::MODULE_BRANDING)
                ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo'])
                ->get();

            foreach ($hostConfigs as $cfg) {
                $val = trim((string) $cfg->value);
                if ($val !== '' && $val !== 'null') {
                    $hostBrandingConfig[$cfg->key] = $val;
                }
            }
        }

        $siteName = (!empty($brandingConfig['site_name']) ? $brandingConfig['site_name'] : null)
            ?? (!empty($partner?->company_name) ? $partner->company_name : null)
            ?? (!empty($hostBrandingConfig['site_name']) ? $hostBrandingConfig['site_name'] : null)
            ?? ($isHost ? config('app.name') : ($partner?->name ?? config('app.name')));

        // Secondary logo first, if not exists or null/empty get primary logo
        $rawLogo = null;
        if (!empty($brandingConfig['secondary_logo']) && $brandingConfig['secondary_logo'] !== 'null') {
            $rawLogo = $brandingConfig['secondary_logo'];
        } elseif (!empty($brandingConfig['primary_logo']) && $brandingConfig['primary_logo'] !== 'null') {
            $rawLogo = $brandingConfig['primary_logo'];
        } elseif (!empty($partner?->company_logo) && $partner->company_logo !== 'null') {
            $rawLogo = $partner->company_logo;
        } elseif (!empty($partner?->partner_image) && $partner->partner_image !== 'null') {
            $rawLogo = $partner->partner_image;
        } elseif (!empty($hostBrandingConfig['secondary_logo']) && $hostBrandingConfig['secondary_logo'] !== 'null') {
            $rawLogo = $hostBrandingConfig['secondary_logo'];
        } elseif (!empty($hostBrandingConfig['primary_logo']) && $hostBrandingConfig['primary_logo'] !== 'null') {
            $rawLogo = $hostBrandingConfig['primary_logo'];
        } elseif (!empty($hostPartner?->company_logo) && $hostPartner->company_logo !== 'null') {
            $rawLogo = $hostPartner->company_logo;
        } elseif (!empty($hostPartner?->partner_image) && $hostPartner->partner_image !== 'null') {
            $rawLogo = $hostPartner->partner_image;
        }

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
                $logoUrl = encodeUrl(asset('storage/' . $cleanPath));
            }
        }

        // Only fall back to default app logo assets when still not found
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
