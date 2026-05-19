<?php

namespace App\Mail\Traits;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\Setting\EmailConfiguration;
use App\Services\Global\Notification\Mail\EmailConfigurationResolver;

trait MailableBranding
{
    protected ?object $resolvedMailConfig = null;
    protected ?int $partnerId = null;
    protected ?string $module = AppConfiguration::MODULE_BRANDING;

    /**
     * Get the Host partner.
     */
    protected function getHostPartner(): ?Partner
    {
        return Partner::where('is_host', 1)->first();
    }
    /**
     * Resolve the mail configuration based on partner context.
     */
    protected function resolveMailConfig(?int $partnerId): void
    {
        if ($partnerId) {
            $partner = Partner::find($partnerId);
            // Only White-Label Partners get their own branding. Others fall back to Host.
            if ($partner && $partner->partner_type != Partner::PARTNER_TYPE_WHITE_LABEL) {
                $host = $this->getHostPartner();
                $partnerId = $host ? $host->id : null;
            }
        } else {
            $host = $this->getHostPartner();
            $partnerId = $host ? $host->id : null;
        }

        $this->partnerId = $partnerId;
        $this->resolvedMailConfig = EmailConfigurationResolver::resolve($partnerId);
    }

    /**
     * Branding data — pulled from resolved config or runtime config.
     */
    protected function getBrandingData(): array
    {
        $module = $this->module;
        $hostPartner = $this->getHostPartner();

        // Fetch all Host branding configs and pluck them into a key => value array for easy fallback
        $hostConfigs = AppConfiguration::where('module', $module)
            ->where('partner_id', $hostPartner?->id)
            ->pluck('value', 'key');

        // 1. Resolve Site Name
        // Try current partner first, fallback to Host config site_name, then Host company name
        $siteName = AppConfiguration::get('site_name', $hostConfigs['site_name'] ?? $hostPartner?->company_name, $module, $this->partnerId);


        $siteName = $siteName ?? config('app.name');

        // 2. Resolve Primary Logo
        $logoPath = AppConfiguration::get('primary_logo', $hostConfigs['primary_logo'] ?? $hostPartner->company_logo, $module, $this->partnerId)
            ?? AppConfiguration::get('partner_logo', $hostConfigs['partner_logo'] ?? $hostPartner->company_logo, $module, $this->partnerId)
            ?? AppConfiguration::get('header_logo', $hostConfigs['header_logo'] ?? $hostPartner->company_logo, $module, $this->partnerId);



        // Handle logo path resolution
        $logoUrl = asset('logo-mail.png'); // Default fallback
        $localPath = public_path('logo-mail.png');

        if ($logoPath && $logoPath !== 'null') {
            if (str_starts_with($logoPath, 'http')) {
                $logoUrl = $logoPath;
            } else {
                $cleanPath = ltrim((string) $logoPath, '/');

                // Standardize path resolution
                if (!str_starts_with($cleanPath, 'site-settings/') && !str_starts_with($cleanPath, 'partners/')) {
                    // Try to determine if it's a partner logo
                    if ($this->partnerId && Partner::find($this->partnerId)?->company_logo == $logoPath) {
                        $cleanPath = "partners/{$this->partnerId}/" . $cleanPath;
                    } else {
                        $cleanPath = "site-settings/" . $cleanPath;
                    }
                }

                $logoUrl = asset('storage/' . $cleanPath);
                $localPath = public_path('storage/' . $cleanPath);
            }
        }

        $businessLogoPath = file_exists($localPath) ? $localPath : public_path('logo-mail.png');

        // 3. Resolve Other Business Info
        $email = AppConfiguration::get('email', $hostConfigs['email'] ?? $hostPartner?->email, $module, $this->partnerId);
        $phone = AppConfiguration::get('phone_number', $hostConfigs['phone_number'] ?? $hostPartner?->phone_number, $module, $this->partnerId);
        $address = AppConfiguration::get('address', $hostConfigs['address'] ?? $hostPartner?->address, $module, $this->partnerId);

        if ($this->partnerId && (!$email || !$phone || !$address)) {
            $hostPartner = Partner::where('is_host', 1)->first();
            if ($hostPartner) {
                $email = $email ?? AppConfiguration::get('email', null, $entityType, $hostPartner->id) ?? $hostPartner->email;
                $phone = $phone ?? AppConfiguration::get('phone_number', null, $entityType, $hostPartner->id) ?? $hostPartner->phone_number;
                $address = $address ?? AppConfiguration::get('address', null, $entityType, $hostPartner->id) ?? $hostPartner->address;
            }
        }

        return [
            'siteName' => $siteName,
            'primaryLogoUrl' => $logoUrl,
            'business_info' => [
                'company_name' => $siteName,
                'email' => $email ?? config('mail.from.address'),
                'phone_number' => $phone ?? '',
                'address_line_1' => $address ?? '',
                'business_logo_url' => $logoUrl,
                'business_logo_path' => $businessLogoPath,
            ],
            'headerLogoSrc' => $logoUrl,
            'footerLogoSrc' => $logoUrl,
            'partnerLogoSrc' => $logoUrl,
        ];
    }

    /**
     * Resolved from_address.
     */
    protected function getFromAddress(): ?string
    {
        return $this->resolvedMailConfig?->from_address
            ?? config('mail.from.address');
    }

    /**
     * Resolved from_name.
     */
    protected function getFromName(): ?string
    {
        $branding = $this->getBrandingData();
        $siteName = $branding['siteName'];
        $defaultName = config('app.name');

        return $siteName ?? config('mail.from.name', $defaultName);
    }

    /**
     * Dynamic subject formatting using the resolved sender name.
     */
    protected function getDynamicSubject(string $subject): string
    {
        $appName = $this->getFromName();
        $defaultName = config('app.name');

        $typos = [$defaultName];

        $newSubject = $subject;
        foreach ($typos as $placeholder) {
            if ($appName !== $placeholder) {
                $newSubject = str_ireplace($placeholder, $appName, $newSubject);
            }
        }

        if ($newSubject !== $subject) {
            return $newSubject;
        }

        if (!str_contains($subject, $appName)) {
            return "$subject";
        }

        return $subject;
    }
}
