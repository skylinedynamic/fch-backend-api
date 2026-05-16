<?php

namespace App\Mail\Traits;

use App\Models\Setting\EmailConfiguration;
use App\Services\Global\Notification\Mail\EmailConfigurationResolver;

trait MailableBranding
{
    protected ?EmailConfiguration $resolvedMailConfig = null;
    protected ?int $partnerId = null;

    /**
     * Resolve the mail configuration based on partner context.
     */
    protected function resolveMailConfig(?int $partnerId): void
    {
        $this->partnerId = $partnerId;
        $this->resolvedMailConfig = EmailConfigurationResolver::resolve($partnerId);
    }

    /**
     * Branding data — pulled from resolved config or runtime config.
     */
    protected function getBrandingData(): array
    {
        $entityType = \App\Models\Partner\Partner::class;

        // 1. Resolve Site Name
        $siteName = \App\Models\Common\AppConfiguration::get('site_name', null, $entityType, $this->partnerId);
        
        // Fallback to partner model name if no app_config site_name
        if (!$siteName && $this->partnerId) {
            $siteName = \App\Models\Partner\Partner::find($this->partnerId)?->company_name;
        }

        // Fallback to host site_name if still null
        if (!$siteName) {
            $hostPartner = \App\Models\Partner\Partner::where('is_host', 1)->first();
            if ($hostPartner) {
                $siteName = \App\Models\Common\AppConfiguration::get('site_name', $hostPartner->company_name, $entityType, $hostPartner->id);
            }
        }

        $siteName = $siteName ?? config('app.name', 'Four Corner Funding');

        // 2. Resolve Primary Logo
        $logoPath = \App\Models\Common\AppConfiguration::get('primary_logo', null, $entityType, $this->partnerId)
                  ?? \App\Models\Common\AppConfiguration::get('partner_logo', null, $entityType, $this->partnerId)
                  ?? \App\Models\Common\AppConfiguration::get('header_logo', null, $entityType, $this->partnerId);

        if (!$logoPath && $this->partnerId) {
            $logoPath = \App\Models\Partner\Partner::find($this->partnerId)?->company_logo;
        }

        // Fallback to host logo if still null
        if (!$logoPath) {
            $hostPartner = \App\Models\Partner\Partner::where('is_host', 1)->first();
            if ($hostPartner) {
                $logoPath = \App\Models\Common\AppConfiguration::get('primary_logo', null, $entityType, $hostPartner->id)
                          ?? $hostPartner->company_logo;
            }
        }

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
                    if ($this->partnerId && \App\Models\Partner\Partner::find($this->partnerId)?->company_logo === $logoPath) {
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
        $email = \App\Models\Common\AppConfiguration::get('email', null, $entityType, $this->partnerId);
        $phone = \App\Models\Common\AppConfiguration::get('phone_number', null, $entityType, $this->partnerId);
        $address = \App\Models\Common\AppConfiguration::get('address', null, $entityType, $this->partnerId);

        if ($this->partnerId && (!$email || !$phone || !$address)) {
            $hostPartner = \App\Models\Partner\Partner::where('is_host', 1)->first();
            if ($hostPartner) {
                $email = $email ?? \App\Models\Common\AppConfiguration::get('email', null, $entityType, $hostPartner->id) ?? $hostPartner->email;
                $phone = $phone ?? \App\Models\Common\AppConfiguration::get('phone_number', null, $entityType, $hostPartner->id) ?? $hostPartner->phone_number;
                $address = $address ?? \App\Models\Common\AppConfiguration::get('address', null, $entityType, $hostPartner->id) ?? $hostPartner->address;
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
        $defaultName = config('app.name') ?? 'Four Corner Funding';

        // return $this->resolvedMailConfig?->from_name
        //     ?? $siteName
        //     ?? config('mail.from.name', $defaultName);

        return $siteName ?? config('mail.from.name', $defaultName);
    }

    /**
     * Dynamic subject formatting using the resolved sender name.
     */
    protected function getDynamicSubject(string $subject): string
    {
        $appName = $this->getFromName();
        $defaultName = 'Four Corner Funding';

        $typos = [$defaultName, 'Four Corner Funding', 'Fourcorner Funding'];

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
