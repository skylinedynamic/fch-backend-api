<?php

namespace App\Services\FCH\Common\Setting;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use App\Models\Setting\EmailConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Collection;

class EmailConfigurationService
{
    private int $cacheTtl = 3600;
    private static ?array $systemDefaults = null;

    private function cacheKey(int|string|null $partnerId): string
    {
        return "email_config_partner_{$partnerId}";
    }

    public function flushCache(int|string|null $partnerId): void
    {
        Cache::forget($this->cacheKey($partnerId));
    }

    public function getConfigurations(int|string|null $partnerId): Collection
    {
        return Cache::remember(
            $this->cacheKey($partnerId),
            $this->cacheTtl,
            fn () => EmailConfiguration::where('partner_id', $partnerId)->get()
        );
    }

    public function getActiveConfig(int|string|null $partnerId): ?EmailConfiguration
    {
        return $this->getConfigurations($partnerId)
            ->where('is_active', true)
            ->first();
    }

    public function storeOrUpdate(array $data): EmailConfiguration
    {
        $partnerId = $data['partner_id'] ?? null;
        $isActive  = filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isActive) {
            EmailConfiguration::where('partner_id', $partnerId)
                ->update(['is_active' => false]);
        }

        if (isset($data['smtp_password']) && $data['smtp_password'] === '••••••••') {
            unset($data['smtp_password']);
        }
        if (isset($data['aws_secret_key']) && $data['aws_secret_key'] === '••••••••') {
            unset($data['aws_secret_key']);
        }

        if (isset($data['id'])) {
            $config = EmailConfiguration::findOrFail($data['id']);
            $data['is_fresh_email'] = true;
            $config->update($data);
        } else {
            $data['is_fresh_email'] = true;
            $config = EmailConfiguration::updateOrCreate(
                ['partner_id' => $partnerId],
                $data
            );
        }

        $this->flushCache($config->partner_id);

        if ($config->partner_id === null || $this->isHostPartner($config->partner_id)) {
            self::$systemDefaults = null;
        }

        return $config->fresh();
    }

    public function toggleStatus(int $id): EmailConfiguration
    {
        $config    = EmailConfiguration::findOrFail($id);
        $newStatus = ! $config->is_active;

        if ($newStatus) {
            EmailConfiguration::where('partner_id', $config->partner_id)
                ->where('id', '!=', $id)
                ->update(['is_active' => false]);
        }

        $config->update(['is_active' => $newStatus, 'is_fresh_email' => true]);
        $this->flushCache($config->partner_id);

        if ($config->partner_id === null || $this->isHostPartner($config->partner_id)) {
            self::$systemDefaults = null;
        }

        return $config->fresh();
    }

    private function isHostPartner(int|string|null $partnerId): bool
    {
        if ($partnerId === null) return false;
        $partner = Partner::find($partnerId);
        return $partner && $partner->is_host;
    }

    private function resolveEntityIdForBranding(): int|string
    {
        if (! auth()->check()) {
            $host = Partner::where('is_host', 1)->first();
            return $host ? $host->id : 1;
        }

        $user    = auth()->user();
        $partner = $user->partner;

        if ($partner && $partner->partner_type === Partner::PARTNER_TYPE_CERTIFIED) {
            $host = Partner::where('is_host', 1)->first();
            return $host ? $host->id : 1;
        }

        return $user->partner_id ?: 1;
    }

    private function resolveEntityIdForMailer(int|string|null $partnerId): int|string
    {
        if ($partnerId === null) return $this->resolveEntityIdForBranding();
        $partner = Partner::find($partnerId);
        if ($partner && $partner->partner_type === Partner::PARTNER_TYPE_CERTIFIED) {
            $host = Partner::where('is_host', 1)->first();
            return $host ? $host->id : 1;
        }
        return $partnerId;
    }

    private function resolveLogoUrl(?string $logoPath, ?string $fallback = null): ?string
    {
        if ($logoPath && $logoPath !== 'null') {
            $path = ltrim((string) $logoPath, '/');
            if (! str_starts_with($path, 'site-settings/')) {
                $path = 'site-settings/' . $path;
            }
            return asset('storage/' . $path);
        }
        return $fallback;
    }

    private function getAppConfigs(int|string $entityId): array
    {
        return \App\Models\Common\AppConfiguration::where('entity_type', 'App\\Models\\Partner\\Partner')
            ->where('entity_id', $entityId)
            ->whereIn('key', ['header_logo', 'footer_logo', 'partner_logo', 'borrower_logo', 'borrower_favicon', 'partner_favicon', 'site_name', 'primary_color', 'secondary_color'])
            ->get()
            ->keyBy('key')
            ->toArray();
    }

    private function buildBrandingConfig(int|string $entityId, ?EmailConfiguration $config): array
    {
        $appConfigs = $this->getAppConfigs($entityId);
        
        if (empty($appConfigs)) {
            $host = Partner::where('is_host', 1)->first();
            if ($host && $host->id != $entityId) {
                $appConfigs = $this->getAppConfigs($host->id);
            }
        }

        $fallbackLogo = asset('logo-mail.png');
        
        $siteName = $config?->site_name ?: ($appConfigs['site_name']['value'] ?? config('app.name'));

        return [
            'app.name'             => $siteName,
            'app.header_logo'      => $this->resolveLogoUrl($appConfigs['header_logo']['value'] ?? null, $fallbackLogo),
            'app.footer_logo'      => $this->resolveLogoUrl($appConfigs['footer_logo']['value'] ?? null, $fallbackLogo),
            'app.partner_logo'     => $this->resolveLogoUrl($appConfigs['partner_logo']['value'] ?? null, $fallbackLogo),
            'app.borrower_logo'    => $this->resolveLogoUrl($appConfigs['borrower_logo']['value'] ?? null, $fallbackLogo),
            'app.borrower_favicon' => $this->resolveLogoUrl($appConfigs['borrower_favicon']['value'] ?? null),
            'app.partner_favicon'  => $this->resolveLogoUrl($appConfigs['partner_favicon']['value'] ?? null),
            'app.primary_color'    => $appConfigs['primary_color']['value'] ?? null,
            'app.secondary_color'  => $appConfigs['secondary_color']['value'] ?? null,
        ];
    }

    private function loadSystemDefaults(): void
    {
        if (self::$systemDefaults !== null) return;

        $hostPartner = Partner::where('is_host', 1)->first();
        $hostId      = $hostPartner?->id;
        $system      = ($hostId ? $this->getActiveConfig($hostId) : null) ?? $this->getActiveConfig(null);

        $brandingEntityId = $this->resolveEntityIdForBranding();
        $branding         = $this->buildBrandingConfig($brandingEntityId, $system);

        self::$systemDefaults = array_merge([
            'mail.default'                 => $system?->email_provider === 'aws_ses' ? 'ses' : 'smtp',
            'mail.mailers.smtp.host'       => $system?->smtp_host,
            'mail.mailers.smtp.port'       => $system?->smtp_port,
            'mail.mailers.smtp.username'   => $system?->smtp_username,
            'mail.mailers.smtp.password'   => $system?->smtp_password,
            'mail.mailers.smtp.encryption' => $system?->smtp_encryption,
            'mail.from.address'            => $system?->from_address,
            'mail.from.name'               => $system?->from_name,
            'services.ses.key'             => $system?->aws_access_key,
            'services.ses.secret'          => $system?->aws_secret_key,
            'services.ses.region'          => $system?->aws_region,
            'mail.bcc'                     => $system?->bcc_emails,
        ], $branding);
    }

    public function applyToMailer(int|string|null $partnerId): \Closure
    {
        $this->loadSystemDefaults();
        $original = self::$systemDefaults;

        $resolvedMailerId = $partnerId !== null ? $this->resolveEntityIdForMailer($partnerId) : null;
        $config = $resolvedMailerId ? $this->getActiveConfig($resolvedMailerId) : null;

        // Fallback to host partner if not enabled or not found
        if (!$config || !$config->enable_custom_configuration) {
            $host = Partner::where('is_host', 1)->first();
            if ($host) $config = $this->getActiveConfig($host->id);
            if (!$config) $config = $this->getActiveConfig(null);
        }

        $brandingEntityId = $partnerId !== null ? $this->resolveEntityIdForMailer($partnerId) : $this->resolveEntityIdForBranding();
        $branding = $this->buildBrandingConfig($brandingEntityId, $config);

        foreach ($branding as $key => $value) {
            Config::set($key, $value);
        }

        if ($config && $config->enable_custom_configuration) {
            $driver = $config->email_provider === 'aws_ses' ? 'ses' : 'smtp';
            Config::set('mail.default', $driver);

            if ($driver === 'smtp') {
                Config::set('mail.mailers.smtp.host',       $config->smtp_host);
                Config::set('mail.mailers.smtp.port',       $config->smtp_port);
                Config::set('mail.mailers.smtp.username',   $config->smtp_username);
                Config::set('mail.mailers.smtp.password',   $config->smtp_password);
                Config::set('mail.mailers.smtp.encryption', $config->smtp_encryption);
            } else {
                Config::set('services.ses.key',    $config->aws_access_key);
                Config::set('services.ses.secret', $config->aws_secret_key);
                Config::set('services.ses.region', $config->aws_region);
            }

            Config::set('mail.from.address', $config->from_address);
            Config::set('mail.from.name',    $config->from_name ?: $branding['app.name']);
            Config::set('mail.bcc',           $config->bcc_emails);
        }

        return function () use ($original) {
            foreach ($original as $key => $value) {
                Config::set($key, $value);
            }
        };
    }
}
