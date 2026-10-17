<?php

namespace App\Support\Mail;

use App\Models\Partner\Partner;
use App\Models\Setting\MailConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the old key-value `email_configurations` into `mail_configurations`,
 * one row per provider a partner actually filled in.
 *
 *   - The provider that was switched on (enable_custom_configuration +
 *     email_provider) becomes the partner's default.
 *   - The host's current settings also become the platform fallback.
 *
 * Nothing moved is verified, the fallback included: every configuration is
 * only trusted once a code sent through it came back. Until the host verifies
 * the fallback (or a partner its own default), that mail is not sent.
 *
 * Safe to run more than once: a provider already moved is left alone.
 * The old table is not touched, so the move can be rolled back.
 */
class LegacyEmailConfigurationImporter
{
    /** Values the old seeder wrote into unused AWS fields. */
    private const PLACEHOLDERS = ['your_aws_access_key', 'your_aws_secret_key'];

    public function run(): void
    {
        if (!Schema::hasTable('email_configurations')) {
            return;
        }

        $legacy = DB::table('email_configurations')
            ->whereNull('deleted_at')
            ->get(['partner_id', 'key', 'value'])
            ->groupBy(fn ($row) => $row->partner_id === null ? 'none' : (string) $row->partner_id);

        $hostId = Partner::where('is_host', true)->value('id');

        foreach ($legacy as $partnerKey => $rows) {
            if ($partnerKey === 'none') {
                continue;
            }

            $partnerId = (int) $partnerKey;
            if (!Partner::whereKey($partnerId)->exists()) {
                continue;
            }

            $values = $rows->pluck('value', 'key')->all();
            $this->importPartner($partnerId, $values);

            if ($hostId !== null && $partnerId === (int) $hostId) {
                $this->seedFallback($values);
            }
        }
    }

    /**
     * @param  array<string, ?string>  $values
     */
    private function importPartner(int $partnerId, array $values): void
    {
        $enabled = filter_var($values['enable_custom_configuration'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $active = $values['email_provider'] ?? MailConfiguration::PROVIDER_SMTP;
        $hasDefault = MailConfiguration::forPartner($partnerId)->where('is_default', true)->exists();

        foreach (array_keys(MailConfiguration::providers()) as $provider) {
            $credentials = $this->credentials($provider, $values);
            if ($credentials === null) {
                continue;
            }

            if (MailConfiguration::forPartner($partnerId)->where('provider', $provider)->exists()) {
                continue;
            }

            MailConfiguration::create([
                'partner_id' => $partnerId,
                'provider' => $provider,
                'from_address' => $this->text($values['from_address'] ?? null),
                'bcc_emails' => $this->text($values['bcc_emails'] ?? null),
                'credentials' => $credentials,
                'is_default' => !$hasDefault && $enabled && $provider === $active,
            ]);
        }
    }

    /**
     * The host's switched-on provider - or, failing that, whichever it filled
     * in - as the fallback, unverified. The host never needed the switch: its
     * settings were the platform's own.
     *
     * @param  array<string, ?string>  $values
     */
    private function seedFallback(array $values): void
    {
        if (MailConfiguration::fallback()->exists()) {
            return;
        }

        $active = $values['email_provider'] ?? MailConfiguration::PROVIDER_SMTP;
        $providers = array_unique(array_merge([$active], array_keys(MailConfiguration::providers())));

        foreach ($providers as $provider) {
            $credentials = $this->credentials($provider, $values);
            if ($credentials === null) {
                continue;
            }

            $fallback = new MailConfiguration([
                'partner_id' => null,
                'provider' => $provider,
                'from_address' => $this->text($values['from_address'] ?? null),
                'bcc_emails' => $this->text($values['bcc_emails'] ?? null),
                'credentials' => $credentials,
            ]);

            if (!$fallback->isUsable()) {
                continue;
            }

            $fallback->save();

            return;
        }
    }

    /**
     * A provider's fields from the old keys, or null when the partner left
     * them blank (or as the seeder's placeholders).
     *
     * @param  array<string, ?string>  $values
     * @return ?array<string, mixed>
     */
    private function credentials(string $provider, array $values): ?array
    {
        $fields = MailConfiguration::providers()[$provider]['fields'] ?? [];
        $credentials = [];
        $filledRequired = true;

        foreach ($fields as $key => $field) {
            $value = $this->text($values[$key] ?? null);
            if ($value !== null && in_array($value, self::PLACEHOLDERS, true)) {
                $value = null;
            }
            if ($value !== null && ($field['type'] ?? null) === 'number' && is_numeric($value)) {
                $value = (int) $value;
            }

            $credentials[$key] = $value;

            if (!empty($field['required']) && empty($field['secret']) && $value === null) {
                $filledRequired = false;
            }
        }

        return $filledRequired ? $credentials : null;
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return ($value === '' || $value === 'null') ? null : $value;
    }
}
