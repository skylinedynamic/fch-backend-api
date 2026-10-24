<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\Partner\Partner;
use App\Models\Setting\SmsConfiguration;
use App\Services\FCH\Common\Notification\Sms\SmsDeliveryException;
use App\Services\FCH\Common\Notification\Sms\SmsGatewayFactory;
use App\Support\Locations;
use Illuminate\Validation\ValidationException;

/**
 * Settings > SMS Configuration, and which partner's account a text is sent
 * from.
 */
class SmsConfigurationService
{
    /** What the settings screen sends back in place of a stored secret. */
    public const MASK = '••••••••';

    public function __construct(private readonly SmsGatewayFactory $gateways)
    {
    }

    public function forPartner(int $partnerId): ?SmsConfiguration
    {
        return SmsConfiguration::where('partner_id', $partnerId)->first();
    }

    /**
     * Saves the partner's configuration. A secret sent back masked or empty
     * keeps the stored one, so the form never has to hold the real token.
     * Switching it on needs everything the provider requires.
     *
     * @throws ValidationException when enabled without complete credentials
     */
    public function save(int $partnerId, array $data): SmsConfiguration
    {
        $configuration = $this->forPartner($partnerId) ?? new SmsConfiguration(['partner_id' => $partnerId]);
        $provider = $this->provider($data['provider']);
        $stored = $configuration->provider === $data['provider'] ? ($configuration->credentials ?? []) : [];

        $credentials = [];
        foreach ($provider['credentials'] as $key) {
            $value = $data[$key] ?? null;
            $keepStored = in_array($key, $provider['secrets'], true) && ($value === null || $value === '' || $value === self::MASK);

            $credentials[$key] = $keepStored ? ($stored[$key] ?? null) : $value;
        }

        $configuration->fill([
            'country' => $data['country'],
            'provider' => $data['provider'],
            'is_enabled' => (bool) $data['is_enabled'],
            'from_number' => $data['from_number'] ?? null,
            'credentials' => $credentials,
        ]);

        // Never stored with empty credentials, enabled or not: an incomplete
        // account is refused rather than saved as a row of nulls.
        $this->assertComplete($configuration);

        $configuration->save();

        return $configuration;
    }

    /**
     * Sends a test text with the partner's own saved account, whether or not
     * it is switched on yet - checking the credentials before enabling them is
     * the point.
     *
     * @throws ValidationException when there is nothing complete to test with
     * @throws SmsDeliveryException when the provider refuses it
     */
    public function sendTest(int $partnerId, string $to): void
    {
        $configuration = $this->forPartner($partnerId);

        if (!$configuration || !$this->isUsable($configuration)) {
            throw ValidationException::withMessages(['phone' => __('sms_config.not_configured')]);
        }

        $this->gateways->make($configuration)->send($to, __('sms_config.test_message', [
            'name' => config('app.name'),
        ]));
    }

    /**
     * The account a text to one of this partner's users is sent from, if any.
     *
     *   - A white-label partner runs its own platform: its own account, and
     *     nothing when that is not enabled. It never falls back to the host's.
     *   - The host and certified partners (and users with no partner) share
     *     the host's account, and get nothing when that is not enabled.
     *
     * Enabled is not enough on its own: an account missing a required
     * credential cannot send and is treated as off.
     */
    public function resolveFor(?int $partnerId): ?SmsConfiguration
    {
        $partner = $partnerId !== null ? Partner::find($partnerId) : null;

        $accountOwnerId = $partner && !$partner->is_host && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL
            ? $partner->id
            : Partner::where('is_host', true)->value('id');

        if ($accountOwnerId === null) {
            return null;
        }

        $configuration = $this->forPartner((int) $accountOwnerId);

        return $configuration && $configuration->is_enabled && $this->isUsable($configuration) ? $configuration : null;
    }

    public function isUsable(SmsConfiguration $configuration): bool
    {
        return $this->missing($configuration) === [];
    }

    /**
     * Countries and their providers, for the settings screen's dropdowns.
     */
    public function options(): array
    {
        $providers = config('sms.providers', []);
        $locations = Locations::map();

        return [
            'countries' => collect(config('sms.countries', []))
                ->map(fn (array $country, string $code) => [
                    'value' => $code,
                    'label' => $locations[$code]['name'] ?? $code,
                    'providers' => collect($country['providers'])
                        ->filter(fn (string $key) => isset($providers[$key]))
                        ->map(fn (string $key) => ['value' => $key, 'label' => $providers[$key]['label']])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Required fields this configuration lacks, keyed as the form names them.
     *
     * @return string[]
     */
    private function missing(SmsConfiguration $configuration): array
    {
        $provider = config('sms.providers', [])[$configuration->provider] ?? null;

        if (!$provider) {
            return ['provider'];
        }

        $missing = array_values(array_filter($provider['required'], fn (string $key) => !$configuration->credential($key)));

        // Twilio sends from a number or, for registered US traffic, a
        // messaging service; one of the two is enough.
        if (!$configuration->from_number && !$configuration->credential('messaging_service_sid')) {
            $missing[] = 'from_number';
        }

        return $missing;
    }

    private function assertComplete(SmsConfiguration $configuration): void
    {
        $missing = $this->missing($configuration);

        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys($missing, __('sms_config.required')));
        }
    }

    private function provider(string $key): array
    {
        return config('sms.providers', [])[$key];
    }
}
