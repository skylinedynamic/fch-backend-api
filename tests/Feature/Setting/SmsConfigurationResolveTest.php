<?php

namespace Tests\Feature\Setting;

use App\Models\Partner\Partner;
use App\Models\Setting\SmsConfiguration;
use App\Services\FCH\Common\Setting\SmsConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whose Twilio account a text goes out from:
 *
 *   - a white-label partner (WLP) runs its own platform, so its users are
 *     texted from its own account - and when that is not enabled, nobody is.
 *     It never borrows the host's;
 *   - the host, and certified partners (who have no account of their own),
 *     are texted from the host's account - and when that is not enabled,
 *     nobody is.
 */
class SmsConfigurationResolveTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Partner $whiteLabel;
    private Partner $certified;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makePartner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $this->whiteLabel = $this->makePartner(false, Partner::PARTNER_TYPE_WHITE_LABEL, 'wlp@example.test');
        $this->certified = $this->makePartner(false, Partner::PARTNER_TYPE_CERTIFIED, 'certified@example.test');
    }

    private function makePartner(bool $isHost, string $type, string $email): Partner
    {
        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => $type,
            'company_name' => $email,
            'full_name' => $email,
            'email' => $email,
        ]);
    }

    private function configure(Partner $partner, bool $enabled, array $credentials = ['account_sid' => 'AC1', 'auth_token' => 't']): SmsConfiguration
    {
        return SmsConfiguration::create([
            'partner_id' => $partner->id,
            'country' => 'USA',
            'provider' => 'twilio',
            'is_enabled' => $enabled,
            'from_number' => '+15005550006',
            'credentials' => $credentials,
        ]);
    }

    private function resolve(?int $partnerId): ?SmsConfiguration
    {
        return app(SmsConfigurationService::class)->resolveFor($partnerId);
    }

    #[Test]
    public function a_white_label_partner_texts_from_its_own_account(): void
    {
        $this->configure($this->host, true);
        $own = $this->configure($this->whiteLabel, true);

        $this->assertTrue($own->is($this->resolve($this->whiteLabel->id)));
    }

    #[Test]
    public function a_white_label_partner_that_is_not_enabled_sends_no_sms_even_if_the_host_is(): void
    {
        $this->configure($this->host, true);
        $this->configure($this->whiteLabel, false);

        $this->assertNull($this->resolve($this->whiteLabel->id));
    }

    #[Test]
    public function a_white_label_partner_without_any_configuration_sends_no_sms(): void
    {
        $this->configure($this->host, true);

        $this->assertNull($this->resolve($this->whiteLabel->id));
    }

    #[Test]
    public function an_enabled_but_incomplete_white_label_account_sends_no_sms(): void
    {
        $this->configure($this->host, true);
        $this->configure($this->whiteLabel, true, ['account_sid' => 'AC1', 'auth_token' => '']);

        $this->assertNull($this->resolve($this->whiteLabel->id));
    }

    #[Test]
    public function the_host_and_certified_partners_text_from_the_hosts_account(): void
    {
        $host = $this->configure($this->host, true);

        $this->assertTrue($host->is($this->resolve($this->host->id)));
        $this->assertTrue($host->is($this->resolve($this->certified->id)));
        $this->assertTrue($host->is($this->resolve(null)));
    }

    #[Test]
    public function when_the_host_is_not_enabled_the_host_and_certified_partners_send_no_sms(): void
    {
        $this->configure($this->host, false);
        $this->configure($this->whiteLabel, true);

        $this->assertNull($this->resolve($this->host->id));
        $this->assertNull($this->resolve($this->certified->id));
        $this->assertNull($this->resolve(null));
    }

    #[Test]
    public function a_certified_partner_never_uses_an_account_of_its_own(): void
    {
        $host = $this->configure($this->host, true);
        $this->configure($this->certified, true);

        $this->assertTrue($host->is($this->resolve($this->certified->id)));
    }
}
