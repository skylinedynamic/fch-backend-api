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

    /**
     * Get configurations and map them to a flat object.
     * Returns a collection containing one flat object (to keep compatibility with controller).
     */
    public function getConfigurations(int|string|null $partnerId): \Illuminate\Support\Collection
    {
        return Cache::remember(
            $this->cacheKey($partnerId),
            $this->cacheTtl,
            function () use ($partnerId) {
                $configs = EmailConfiguration::where('partner_id', $partnerId)->get();
                if ($configs->isEmpty()) {
                    return collect();
                }

                $flat = new \stdClass();
                $flat->partner_id = $partnerId;
                
                foreach ($configs as $config) {
                    $flat->{$config->key} = $config->value;
                }

                // Add standard properties to prevent undefined property errors
                $flat->id = $partnerId ?: 0; // Fake ID for the frontend if needed
                
                return collect([$flat]);
            }
        );
    }

    public function getActiveConfig(int|string|null $partnerId): ?\stdClass
    {
        $configs = $this->getConfigurations($partnerId);
        $config = $configs->first();
        
        if ($config && isset($config->is_active) && filter_var($config->is_active, FILTER_VALIDATE_BOOLEAN)) {
            return $config;
        }
        
        return null;
    }

    public function storeOrUpdate(array $data): \stdClass
    {
        $partnerId = $data['partner_id'] ?? null;
        $isActive  = filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (isset($data['smtp_password']) && $data['smtp_password'] === '••••••••') {
            unset($data['smtp_password']);
        }
        if (isset($data['aws_secret_key']) && $data['aws_secret_key'] === '••••••••') {
            unset($data['aws_secret_key']);
        }

        // Always set is_fresh_email to true upon save
        $data['is_fresh_email'] = true;
        
        // Remove 'id' if it's there, as we don't store it in the key-value table directly
        unset($data['id']);
        unset($data['partner_id']);

        // Update each key-value pair
        foreach ($data as $key => $value) {
            EmailConfiguration::updateOrCreate(
                [
                    'partner_id' => $partnerId,
                    'key' => $key
                ],
                [
                    'value' => $value
                ]
            );
        }

        $this->flushCache($partnerId);
        
        if ($partnerId) {
            \App\Models\User\User::where('partner_id', $partnerId)->update(['is_refresh' => true]);
        }

        if ($partnerId === null || $this->isHostPartner($partnerId)) {
            self::$systemDefaults = null;
        }

        return $this->getConfigurations($partnerId)->first();
    }

    public function toggleStatus(int $id): \stdClass
    {
        // $id is meaningless now, but we can assume it represents partner_id if it's a fake ID, 
        // however the controller calls this with the fake ID.
        // It's better to fetch by partner_id. But since frontend passes the fake ID (which is partner_id), let's use it as partner_id.
        $partnerId = $id === 0 ? null : $id;
        
        $configObj = $this->getConfigurations($partnerId)->first();
        if (!$configObj) {
            abort(404, 'Configuration not found');
        }

        $currentStatus = isset($configObj->is_active) ? filter_var($configObj->is_active, FILTER_VALIDATE_BOOLEAN) : false;
        $newStatus = !$currentStatus;

        EmailConfiguration::updateOrCreate(
            ['partner_id' => $partnerId, 'key' => 'is_active'],
            ['value' => $newStatus]
        );
        
        EmailConfiguration::updateOrCreate(
            ['partner_id' => $partnerId, 'key' => 'is_fresh_email'],
            ['value' => true]
        );

        $this->flushCache($partnerId);

        if ($partnerId) {
            \App\Models\User\User::where('partner_id', $partnerId)->update(['is_refresh' => true]);
        }

        if ($partnerId === null || $this->isHostPartner($partnerId)) {
            self::$systemDefaults = null;
        }

        return $this->getConfigurations($partnerId)->first();
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

    private function buildBrandingConfig(int|string $entityId, ?\stdClass $config): array
    {
        $appConfigs = $this->getAppConfigs($entityId);
        
        if (empty($appConfigs)) {
            $host = Partner::where('is_host', 1)->first();
            if ($host && $host->id != $entityId) {
                $appConfigs = $this->getAppConfigs($host->id);
            }
        }

        $fallbackLogo = asset('logo-mail.png');
        
        $siteName = $config->site_name ?? ($appConfigs['site_name']['value'] ?? config('app.name'));

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
            'mail.default'                 => ($system->email_provider ?? null) === 'aws_ses' ? 'ses' : 'smtp',
            'mail.mailers.smtp.host'       => $system->smtp_host ?? null,
            'mail.mailers.smtp.port'       => $system->smtp_port ?? null,
            'mail.mailers.smtp.username'   => $system->smtp_username ?? null,
            'mail.mailers.smtp.password'   => $system->smtp_password ?? null,
            'mail.mailers.smtp.encryption' => $system->smtp_encryption ?? null,
            'mail.from.address'            => $system->from_address ?? null,
            // 'mail.from.name'               => $system->from_name ?? null,
            'services.ses.key'             => $system->aws_access_key ?? null,
            'services.ses.secret'          => $system->aws_secret_key ?? null,
            'services.ses.region'          => $system->aws_region ?? null,
            'mail.bcc'                     => $system->bcc_emails ?? null,
        ], $branding);
    }

    public function applyToMailer(int|string|null $partnerId): \Closure
    {
        if ($partnerId) {
            $partner = Partner::find($partnerId);
            // Only White-Label Partners get their own branding. Others fall back to Host.
            if ($partner && $partner->partner_type !== Partner::PARTNER_TYPE_WHITE_LABEL) {
                $host = Partner::where('is_host', 1)->first();
                $partnerId = $host ? $host->id : null;
            }
        }

        $this->loadSystemDefaults();
        $original = self::$systemDefaults;

        $resolvedMailerId = $partnerId !== null ? $this->resolveEntityIdForMailer($partnerId) : null;
        $config = $resolvedMailerId ? $this->getActiveConfig($resolvedMailerId) : null;

        // Fallback to host partner if not enabled or not found
        if (!$config || empty($config->enable_custom_configuration)) {
            $host = Partner::where('is_host', 1)->first();
            if ($host) $config = $this->getActiveConfig($host->id);
            if (!$config) $config = $this->getActiveConfig(null);
        }

        $brandingEntityId = $partnerId !== null ? $this->resolveEntityIdForMailer($partnerId) : $this->resolveEntityIdForBranding();
        $branding = $this->buildBrandingConfig($brandingEntityId, $config);

        foreach ($branding as $key => $value) {
            Config::set($key, $value);
        }

        if ($config && !empty($config->enable_custom_configuration)) {
            $driver = ($config->email_provider ?? 'smtp') === 'aws_ses' ? 'ses' : 'smtp';
            Config::set('mail.default', $driver);

            if ($driver === 'smtp') {
                Config::set('mail.mailers.smtp.host',       $config->smtp_host ?? null);
                Config::set('mail.mailers.smtp.port',       $config->smtp_port ?? null);
                Config::set('mail.mailers.smtp.username',   $config->smtp_username ?? null);
                Config::set('mail.mailers.smtp.password',   $config->smtp_password ?? null);
                Config::set('mail.mailers.smtp.encryption', $config->smtp_encryption ?? null);
            } else {
                Config::set('services.ses.key',    $config->aws_access_key ?? null);
                Config::set('services.ses.secret', $config->aws_secret_key ?? null);
                Config::set('services.ses.region', $config->aws_region ?? null);
            }

            Config::set('mail.from.address', $config->from_address ?? null);
            // Config::set('mail.from.name',    $config->from_name ?: $branding['app.name']);
            Config::set('mail.bcc',           $config->bcc_emails ?? null);
        }

        return function () use ($original) {
            foreach ($original as $key => $value) {
                Config::set($key, $value);
            }
        };
    }
}
