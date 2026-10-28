<?php

namespace App\Mail\Traits;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Support\Branding\BrandPalette;
use App\Support\Branding\MailBrandContext;
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

        // Colours and the surface-named logos are read alongside the legacy keys so
        // the email can be branded rather than shipping a fixed white header band.
        $wantedKeys = [
            'site_name',
            'site_slogan',
            'primary_color',
            'secondary_color',
            'logo_mail',
            'logo_sidebar',
            'logo_document',
            'logo_mobile',
        ];

        $brandingConfig = [];
        if ($resolvedPartnerId) {
            $configs = AppConfiguration::where('partner_id', $resolvedPartnerId)
                ->where('module', AppConfiguration::MODULE_BRANDING)
                ->whereIn('key', $wantedKeys)
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
                ->whereIn('key', $wantedKeys)
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

        // The mail header is white, so the light-background lineage comes first -
        // a white logo on a white header would be invisible. The partner's own
        // keys are exhausted before falling back to the host's.
        $logoPreference = ['logo_mail', 'logo_document', 'logo_mobile', 'logo_sidebar'];

        $pickLogo = static function (array $config) use ($logoPreference): ?string {
            foreach ($logoPreference as $key) {
                if (!empty($config[$key]) && $config[$key] !== 'null') {
                    return $config[$key];
                }
            }

            return null;
        };

        $rawLogo = $pickLogo($brandingConfig);

        if (!$rawLogo && !empty($partner?->company_logo) && $partner->company_logo !== 'null') {
            $rawLogo = $partner->company_logo;
        }
        if (!$rawLogo && !empty($partner?->partner_image) && $partner->partner_image !== 'null') {
            $rawLogo = $partner->partner_image;
        }
        if (!$rawLogo) {
            $rawLogo = $pickLogo($hostBrandingConfig);
        }
        if (!$rawLogo && !empty($hostPartner?->company_logo) && $hostPartner->company_logo !== 'null') {
            $rawLogo = $hostPartner->company_logo;
        }
        if (!$rawLogo && !empty($hostPartner?->partner_image) && $hostPartner->partner_image !== 'null') {
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

        // Colours, inherited partner -> host -> product default, then derived the
        // same way the portals derive them so an email matches the dashboard.
        $primary = BrandPalette::firstUsable(
            $brandingConfig['primary_color'] ?? null,
            $hostBrandingConfig['primary_color'] ?? null,
            BrandPalette::DEFAULT_PRIMARY
        );
        $secondary = BrandPalette::firstUsable(
            $brandingConfig['secondary_color'] ?? null,
            $hostBrandingConfig['secondary_color'] ?? null,
            BrandPalette::DEFAULT_SECONDARY
        );
        // The tagline is the sender's own: the email wears the partner's name
        // and logo, so the host's slogan under them read as the partner's
        // (R2 BUG-073). No partner slogan means no tagline.
        $slogan = $brandingConfig['site_slogan'] ?? null;

        $branding = [
            'siteName' => $siteName,
            'logoUrl' => $logoUrl,
            'siteSlogan' => BrandPalette::usable($slogan) ? \trim((string) $slogan) : null,

            // Consumed by resources/views/emails/layouts/mail.blade.php
            'brandPrimary' => $primary,
            'brandSecondary' => $secondary,
            'brandOnPrimary' => BrandPalette::onColor($primary),
            'brandOnSecondary' => BrandPalette::onColor($secondary),
            'brandPrimaryInk' => BrandPalette::ink($primary),
            'brandPrimarySubtle' => BrandPalette::subtle($primary),
        ];

        // Mailables copy individual keys into their own view data, so the layout
        // would never see the colours. Record them for the view composer instead.
        MailBrandContext::set($branding);

        return $branding;
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
