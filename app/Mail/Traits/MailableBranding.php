<?php

namespace App\Mail\Traits;

use App\Models\Setting\EmailConfiguration;
use App\Services\Global\Notification\Mail\EmailConfigurationResolver;

trait MailableBranding
{
    protected ?EmailConfiguration $resolvedMailConfig = null;

    /**
     * Resolve the mail configuration based on partner context.
     */
    protected function resolveMailConfig(?int $partnerId): void
    {
        $this->resolvedMailConfig = EmailConfigurationResolver::resolve($partnerId);
    }

    /**
     * Branding data — pulled from resolved config or runtime config.
     */
    protected function getBrandingData(): array
    {
        $defaultAppName = 'Four Corner Funding';
        
        return [
            'siteName'       => config('app.name')
                                    ?? $this->resolvedMailConfig?->site_name
                                    ?? $defaultAppName,
            'headerLogoSrc'  => config('app.header_logo'),
            'footerLogoSrc'  => config('app.footer_logo'),
            'partnerLogoSrc' => config('app.partner_logo'),
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
        $defaultName = config('app.name') ?? 'Four Corner Funding';
        return $this->resolvedMailConfig?->from_name
            ?? config('mail.from.name', $defaultName);
    }

    /**
     * Dynamic subject formatting using the resolved sender name.
     */
    protected function getDynamicSubject(string $subject): string
    {
        $appName     = $this->getFromName();
        $defaultName = 'Four Corner Funding';

        $typos = [$defaultName, 'Four Corner Funding'];

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
            return "[$appName] $subject";
        }

        return $subject;
    }
}
