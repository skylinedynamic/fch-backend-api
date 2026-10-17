<?php

namespace Tests\Feature\MailConfiguration;

use App\Mail\Setting\MailConfigurationVerificationMail;
use App\Models\Partner\Partner;
use App\Models\Setting\MailConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one fallback mail configuration for the whole platform. Only the host
 * partner's own account (entity_type Partner) can see or change it.
 */
class FallbackMailConfigurationTest extends TestCase
{
    use RefreshDatabase;
    use MailConfigurationFixtures;

    private const URL = '/api/v1/common/mail-configurations/fallback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
    }

    /**
     * @return array<string, mixed>
     */
    private function smtpPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'smtp',
            'from_address' => 'noreply@fch.test',
            'smtp_host' => 'smtp.fch.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'platform',
            'smtp_password' => 'platform-secret',
        ], $overrides);
    }

    #[Test]
    public function the_host_partner_sets_the_fallback_for_everyone(): void
    {
        $this->actingAsUser($this->owner($this->host));

        $this->postJson(self::URL, $this->smtpPayload())
            ->assertOk()
            ->assertJsonPath('data.configuration.provider', 'smtp')
            ->assertJsonPath('data.configuration.smtp_password', '••••••••')
            ->assertJsonPath('data.configuration.is_verified', false);

        $fallback = MailConfiguration::whereNull('partner_id')->sole();
        $this->assertSame('platform-secret', $fallback->credentials['smtp_password']);
    }

    #[Test]
    public function there_is_only_ever_one_fallback(): void
    {
        $this->actingAsUser($this->owner($this->host));

        $this->postJson(self::URL, $this->smtpPayload())->assertOk();
        $this->postJson(self::URL, [
            'provider' => 'aws_ses',
            'from_address' => 'noreply@fch.test',
            'aws_access_key' => 'AKIAFCH',
            'aws_secret_key' => 'ses-secret',
            'aws_region' => 'us-east-1',
        ])->assertOk()->assertJsonPath('data.configuration.provider', 'aws_ses');

        $this->assertSame(1, MailConfiguration::whereNull('partner_id')->count());
    }

    #[Test]
    public function the_host_partner_reads_it_back(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $this->smtpConfig(null);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.configuration.smtp_host', 'smtp.fallback.test')
            ->assertJsonPath('data.providers.0.value', 'smtp');
    }

    #[Test]
    public function the_host_partner_verifies_it_with_a_code(): void
    {
        $owner = $this->actingAsUser($this->owner($this->host));
        $this->smtpConfig(null);
        $code = null;

        Mail::fake();
        $this->postJson(self::URL . '/send-code')->assertOk();
        Mail::assertSent(MailConfigurationVerificationMail::class, function ($mail) use (&$code, $owner) {
            $code = $mail->code;

            return $mail->hasTo($owner->email);
        });

        $this->postJson(self::URL . '/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.configuration.is_verified', true);
    }

    #[Test]
    public function only_the_host_partners_own_account_may_touch_it(): void
    {
        $certified = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Certified Co',
            'full_name' => 'Certified Owner',
            'email' => 'certified-' . uniqid() . '@example.test',
        ]);
        $this->smtpConfig(null);

        foreach ([
            $this->employee($this->host),
            $this->owner($this->whiteLabel),
            $this->owner($certified),
        ] as $user) {
            $this->actingAsUser($user);

            $this->getJson(self::URL)->assertForbidden();
            $this->postJson(self::URL, $this->smtpPayload())->assertForbidden();
            $this->postJson(self::URL . '/send-code')->assertForbidden();
            $this->postJson(self::URL . '/verify', ['code' => '123456'])->assertForbidden();
        }

        $this->assertSame('smtp.fallback.test', MailConfiguration::whereNull('partner_id')->sole()->credentials['smtp_host']);
    }

    #[Test]
    public function the_host_partner_is_told_it_can_manage_the_fallback(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $this->getJson('/api/v1/common/mail-configurations')->assertJsonPath('data.can_manage_fallback', true);

        $this->actingAsUser($this->employee($this->host));
        $this->getJson('/api/v1/common/mail-configurations')->assertJsonPath('data.can_manage_fallback', false);
    }
}
