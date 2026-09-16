<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\User\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use App\Models\Setting\EmailConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Collection;

class EmailConfigurationService
{
    private static ?array $systemDefaults = null;

    /**
     * Get configurations and map them to a flat object.
     * Returns a collection containing one flat object (to keep compatibility with controller).
     */
    public function getConfigurations(int|string|null $partnerId): \Illuminate\Support\Collection
    {
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

    public function storeOrUpdate(array $data): \stdClass
    {
        $partnerId = auth()->user()->partner_id ?? null;

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

        if ($partnerId) {
            User::where('partner_id', $partnerId)->update(['is_refresh' => true]);
        }

        // Cache::forget("dynamic_mail_config_" . ($partnerId ?? 'system'));

        if ($partnerId === null || $this->isHostPartner($partnerId)) {
            self::$systemDefaults = null;
        }

        return $this->getConfigurations($partnerId)->first();
    }

    public function toggleStatus(): \stdClass
    {
        $partnerId = auth()->user()->partner_id ?? null;

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

        if ($partnerId) {
            User::where('partner_id', $partnerId)->update(['is_refresh' => true]);
        }

        Cache::forget("dynamic_mail_config_" . ($partnerId ?? 'system'));

        if ($partnerId === null || $this->isHostPartner($partnerId)) {
            self::$systemDefaults = null;
        }

        return $this->getConfigurations($partnerId)->first();
    }

    /**
     * The mail credentials a partner actually sends with.
     *
     * Credentials are DATABASE-ONLY. config/mail.php no longer carries SMTP or
     * SES credentials from the environment, so there is no silent .env fallback
     * behind this: whatever this returns is what gets used, and null means the
     * mail cannot be sent at all.
     *
     * Two steps, in order:
     *
     *   1. The partner's own row, if they have switched custom configuration on.
     *   2. The host's row - the platform's own mailbox. A partner who has not
     *      configured a mail server sends through the platform's, which is what
     *      used to come from .env and is the same host-as-fallback rule branding
     *      already follows.
     *
     * The host is not required to have the flag on: its row IS the platform
     * configuration, so a usable row is enough.
     */
    public function resolveFor(int|string|null $partnerId): ?\stdClass
    {
        $own = $this->getConfigurations($partnerId)->first();

        if ($own && !empty($own->enable_custom_configuration) && $this->isUsable($own)) {
            return $own;
        }

        $hostId = Partner::where('is_host', true)->value('id');

        // Already tried above, and trying it again would loop on the same row.
        if ($hostId === null || (string) $hostId === (string) $partnerId) {
            return null;
        }

        $host = $this->getConfigurations($hostId)->first();

        return ($host && $this->isUsable($host)) ? $host : null;
    }

    /**
     * Whether a configuration carries enough to actually connect.
     *
     * A row can exist with every field blank - the settings screen creates keys
     * as soon as it is opened - and handing that to Symfony produces an obscure
     * transport error rather than a usable message.
     */
    public function isUsable(?\stdClass $config): bool
    {
        if (!$config) {
            return false;
        }

        if (($config->email_provider ?? 'smtp') === 'aws_ses') {
            return !empty($config->aws_access_key) && !empty($config->aws_secret_key);
        }

        return !empty($config->smtp_host) && !empty($config->smtp_username);
    }

    private function isHostPartner(int|string|null $partnerId): bool
    {
        if ($partnerId === null)
            return false;

        $partner = Partner::find($partnerId);
        return $partner && $partner->is_host;
    }
}
