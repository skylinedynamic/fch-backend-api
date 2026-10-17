<?php

namespace App\Services\FCH\Common\Setting;

use App\Exceptions\MailConfiguration\VerificationCodeNotSent;
use App\Mail\Setting\MailConfigurationVerificationMail;
use App\Models\Setting\MailConfiguration;
use App\Models\User\User;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Settings > Email Configuration: a partner's providers, the platform-wide
 * fallback, and verifying either with a code.
 *
 * Which settings mail is actually sent with is EmailConfigurationService.
 */
class MailConfigurationService
{
    /** What the settings screen sends back in place of a stored secret. */
    public const MASK = '••••••••';

    /**
     * @return Collection<int, MailConfiguration>
     */
    public function forPartner(int $partnerId): Collection
    {
        $order = array_flip(array_keys(MailConfiguration::providers()));

        return MailConfiguration::forPartner($partnerId)->get()
            ->sortBy(fn (MailConfiguration $config) => $order[$config->provider] ?? PHP_INT_MAX)
            ->values();
    }

    public function fallback(): ?MailConfiguration
    {
        return MailConfiguration::fallback()->first();
    }

    /**
     * Saves one provider's settings for a partner, or - with no partner - the
     * fallback, which is a single row whatever its provider.
     *
     * A secret sent back masked or empty keeps the stored one, so the form never
     * has to hold the real password. Changing anything that decides how mail is
     * sent leaves the configuration unverified.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when a required secret was never given
     */
    public function save(?int $partnerId, array $data): MailConfiguration
    {
        $provider = $data['provider'];

        $config = $partnerId === null
            ? ($this->fallback() ?? new MailConfiguration(['partner_id' => null]))
            : (MailConfiguration::forPartner($partnerId)->where('provider', $provider)->first()
                ?? new MailConfiguration(['partner_id' => $partnerId, 'provider' => $provider]));

        $stored = $config->provider === $provider ? ($config->credentials ?? []) : [];
        $credentials = [];
        $missing = [];

        foreach (MailConfiguration::providers()[$provider]['fields'] as $key => $field) {
            $value = $data[$key] ?? null;
            $isSecret = !empty($field['secret']);

            if ($isSecret && ($value === null || $value === '' || $value === self::MASK)) {
                $value = $stored[$key] ?? null;
            }
            if (($field['type'] ?? null) === 'number' && is_numeric($value)) {
                $value = (int) $value;
            }

            $credentials[$key] = $value === '' ? null : $value;

            if (!empty($field['required']) && $credentials[$key] === null) {
                $missing[$key] = __('mail_config.field_required', ['field' => $field['label']]);
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $config->fill([
            'provider' => $provider,
            'from_address' => $data['from_address'],
            'bcc_emails' => $data['bcc_emails'] ?? null,
            'credentials' => $credentials,
        ]);

        if ($config->verified_at !== null && !$config->isVerified()) {
            $this->clearVerification($config);
        }

        $config->save();
        $this->refreshPartnerUsers($config);

        return $config;
    }

    public function delete(MailConfiguration $config): void
    {
        Cache::forget($this->codeKey($config));
        $config->delete();
        $this->refreshPartnerUsers($config);
    }

    /**
     * Whether a partner has email set up decides the "finish your setup"
     * prompt, which its signed-in users only re-read when told to refresh.
     */
    private function refreshPartnerUsers(MailConfiguration $config): void
    {
        if (!$config->isFallback()) {
            User::where('partner_id', $config->partner_id)->update(['is_refresh' => true]);
        }
    }

    /**
     * @throws ValidationException when the configuration is not verified
     */
    public function makeDefault(MailConfiguration $config): MailConfiguration
    {
        if (!$config->isVerified() || !$config->isUsable()) {
            throw ValidationException::withMessages(['id' => __('mail_config.not_verified_for_default')]);
        }

        DB::transaction(function () use ($config) {
            MailConfiguration::forPartner($config->partner_id)
                ->whereKeyNot($config->id)
                ->update(['is_default' => false]);

            $config->forceFill(['is_default' => true])->save();
        });

        return $config->refresh();
    }

    /**
     * Sends a verification code through these settings to the user.
     *
     * @throws ValidationException when the settings are incomplete
     * @throws ThrottleRequestsException when a code was sent under a minute ago
     * @throws VerificationCodeNotSent when the settings could not send it
     */
    public function sendCode(MailConfiguration $config, User $user): void
    {
        if (!$config->isUsable()) {
            throw ValidationException::withMessages(['provider' => __('mail_config.not_complete')]);
        }

        if (Cache::has($this->waitKey($config))) {
            throw new ThrottleRequestsException(__('mail_config.code_wait'));
        }

        $settings = config('mail_configuration.verification');
        $length = (int) $settings['code_length'];
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        try {
            // The Notifier lets a send failure through: the mailable asks for
            // that (see MailConfig::useMailSettings()), so it lands below.
            app(Notifier::class)->send(
                'settings.mail_configuration_code',
                mail: (new MailConfigurationVerificationMail($code, $config, $user))->to($user->email),
            );
        } catch (\Throwable $e) {
            Log::warning(__('mail_config.logs.code_not_sent'), [
                'mail_configuration_id' => $config->id,
                'partner_id' => $config->partner_id,
                'provider' => $config->provider,
                'error' => $e->getMessage(),
            ]);

            throw new VerificationCodeNotSent(__('mail_config.code_not_sent'), previous: $e);
        }

        $expiresAt = Carbon::now()->addMinutes((int) $settings['ttl_minutes']);

        Cache::put($this->codeKey($config), [
            'hash' => $this->hashCode($code),
            'fingerprint' => $config->fingerprint(),
            'email' => $user->email,
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        Cache::put($this->waitKey($config), true, Carbon::now()->addSeconds((int) $settings['resend_seconds']));
    }

    /**
     * Checks a code. The right one, for the settings it was sent through, marks
     * them verified; a partner's first verified provider becomes its default.
     *
     * @throws ValidationException when the code is wrong, used up or expired
     */
    public function verify(MailConfiguration $config, string $code, User $user): MailConfiguration
    {
        $key = $this->codeKey($config);
        $entry = Cache::get($key);
        $maxAttempts = (int) config('mail_configuration.verification.max_attempts');

        // A code sent for other settings, or one tried too often, is spent.
        if (!is_array($entry) || $entry['fingerprint'] !== $config->fingerprint() || $entry['attempts'] >= $maxAttempts) {
            Cache::forget($key);

            throw ValidationException::withMessages(['code' => __('mail_config.code_invalid')]);
        }

        if (!hash_equals($entry['hash'], $this->hashCode(trim($code)))) {
            $entry['attempts']++;

            if ($entry['attempts'] >= $maxAttempts) {
                Cache::forget($key);
            } else {
                Cache::put($key, $entry, Carbon::createFromTimestamp($entry['expires_at']));
            }

            throw ValidationException::withMessages(['code' => __('mail_config.code_invalid')]);
        }

        Cache::forget($key);

        $config->forceFill([
            'verified_at' => Carbon::now(),
            'verified_fingerprint' => $config->fingerprint(),
            'verified_by' => $user->id,
            'verified_to' => $entry['email'],
        ]);

        if (!$config->isFallback() && !MailConfiguration::forPartner($config->partner_id)->where('is_default', true)->exists()) {
            $config->is_default = true;
        }

        $config->save();

        return $config->refresh();
    }

    /**
     * Providers and their fields, for the settings screen's form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function providerOptions(): array
    {
        return collect(MailConfiguration::providers())
            ->map(fn (array $provider, string $value) => [
                'value' => $value,
                'label' => $provider['label'],
                'fields' => collect($provider['fields'])
                    ->map(fn (array $field, string $key) => [
                        'key' => $key,
                        'label' => $field['label'],
                        'type' => $field['type'],
                        'required' => (bool) ($field['required'] ?? false),
                        'secret' => (bool) ($field['secret'] ?? false),
                        'options' => collect($field['options'] ?? [])
                            ->map(fn (string $label, string $option) => ['value' => $option, 'label' => $label])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function clearVerification(MailConfiguration $config): void
    {
        $config->forceFill([
            'verified_at' => null,
            'verified_fingerprint' => null,
            'verified_by' => null,
            'verified_to' => null,
        ]);
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function codeKey(MailConfiguration $config): string
    {
        return "mail-configuration:{$config->id}:code";
    }

    private function waitKey(MailConfiguration $config): string
    {
        return "mail-configuration:{$config->id}:wait";
    }
}
