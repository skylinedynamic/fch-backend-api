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

    private function isHostPartner(int|string|null $partnerId): bool
    {
        if ($partnerId === null)
            return false;

        $partner = Partner::find($partnerId);
        return $partner && $partner->is_host;
    }
}
