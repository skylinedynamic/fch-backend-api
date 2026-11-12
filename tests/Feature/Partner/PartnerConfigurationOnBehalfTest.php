<?php

namespace Tests\Feature\Partner;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\Setting\MailConfiguration;
use App\Models\Setting\SmsConfiguration;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Partner profile › Configuration: the host edits a partner's Email, SMS,
 * Branding and Payment settings through the same endpoints the partner uses
 * on its own Settings page, naming the partner with `partner_id`.
 *
 * Only a host user holding `manage partner configuration` may name another
 * partner; anyone else gets 403 (R3-BUG-001 - a partner could read and switch
 * off another partner's payment gateways). Naming one's own partner, or
 * leaving `partner_id` out, works on the caller's own settings as before.
 */
class PartnerConfigurationOnBehalfTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    /** The Email and SMS routes' own (pre-existing) route permissions. */
    private const ROUTE_PERMISSIONS = [
        'update email configuration settings',
        'update sms configuration settings',
    ];

    /** Who may name another partner (config/permissions.php, host only). */
    private const ON_BEHALF_PERMISSION = 'manage partner configuration';

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner(['company_name' => 'Acme Lending']);
    }

    private function hostAdmin(bool $mayManagePartners = true): User
    {
        $permissions = $mayManagePartners
            ? [...self::ROUTE_PERMISSIONS, self::ON_BEHALF_PERMISSION]
            : self::ROUTE_PERMISSIONS;

        return $this->actingAsUser($this->makePartnerAdmin($this->host, $permissions));
    }

    private function smsPayload(array $overrides = []): array
    {
        return array_merge([
            'country' => 'USA',
            'provider' => 'twilio',
            'is_enabled' => true,
            'account_sid' => 'AC0123456789abcdef0123456789abcdef',
            'auth_token' => 'secret-token',
            'from_number' => '+15005550006',
            'messaging_service_sid' => null,
        ], $overrides);
    }

    private function smtpPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'smtp',
            'from_address' => 'noreply@acme.test',
            'smtp_host' => 'smtp.acme.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'acme',
            'smtp_password' => 'smtp-secret',
        ], $overrides);
    }

    // -- SMS ------------------------------------------------------------------

    #[Test]
    public function the_host_saves_and_reads_a_partners_sms_configuration(): void
    {
        $this->hostAdmin();

        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload(['partner_id' => $this->partner->id]))->assertOk();

        $this->assertTrue(SmsConfiguration::where('partner_id', $this->partner->id)->exists());
        $this->assertFalse(SmsConfiguration::where('partner_id', $this->host->id)->exists());

        $this->getJson('/api/v1/common/sms-configuration?partner_id=' . $this->partner->id)
            ->assertOk()
            ->assertJsonPath('data.configuration.from_number', '+15005550006');
    }

    // -- Email ----------------------------------------------------------------

    #[Test]
    public function the_host_saves_and_lists_a_partners_mail_configuration(): void
    {
        $this->hostAdmin();

        $this->postJson('/api/v1/common/mail-configurations', $this->smtpPayload(['partner_id' => $this->partner->id]))->assertOk();

        $this->assertTrue(MailConfiguration::where('partner_id', $this->partner->id)->exists());
        $this->assertFalse(MailConfiguration::where('partner_id', $this->host->id)->exists());

        $this->getJson('/api/v1/common/mail-configurations?partner_id=' . $this->partner->id)
            ->assertOk()
            ->assertJsonFragment(['smtp_host' => 'smtp.acme.test'])
            // The host's own fallback mailbox is not part of the partner's settings.
            ->assertJsonPath('data.can_manage_fallback', false);
    }

    #[Test]
    public function the_host_cannot_reach_another_partners_mail_configuration_through_its_own_id(): void
    {
        $this->hostAdmin();
        $theirs = MailConfiguration::create([
            'partner_id' => $this->partner->id,
            'provider' => MailConfiguration::PROVIDER_SMTP,
            'from_address' => 'noreply@acme.test',
            'credentials' => ['smtp_host' => 'smtp.acme.test', 'smtp_port' => 587, 'smtp_username' => 'acme', 'smtp_password' => 'x'],
        ]);

        // Without partner_id the host is working on its own settings.
        $this->deleteJson("/api/v1/common/mail-configurations/{$theirs->id}")->assertNotFound();
        $this->deleteJson("/api/v1/common/mail-configurations/{$theirs->id}?partner_id={$this->partner->id}")->assertOk();
    }

    // -- Branding -------------------------------------------------------------

    #[Test]
    public function the_host_saves_and_reads_a_partners_branding(): void
    {
        $this->hostAdmin();

        $this->postJson('/api/v1/common/app-configurations/save', [
            'module' => AppConfiguration::MODULE_BRANDING,
            'partner_id' => $this->partner->id,
            'primary_color' => '#123456',
        ])->assertOk();

        $this->assertSame('#123456', AppConfiguration::where('partner_id', $this->partner->id)
            ->where('module', AppConfiguration::MODULE_BRANDING)->where('key', 'primary_color')->value('value'));
        $this->assertFalse(AppConfiguration::where('partner_id', $this->host->id)->where('key', 'primary_color')->exists());
        // partner_id is the target, not a setting.
        $this->assertFalse(AppConfiguration::where('key', 'partner_id')->exists());

        $this->postJson('/api/v1/common/app-configurations', [
            'module' => AppConfiguration::MODULE_BRANDING,
            'partner_id' => $this->partner->id,
        ])->assertOk()->assertJsonFragment(['primary_color' => '#123456']);
    }

    #[Test]
    public function only_branding_can_be_edited_for_another_partner(): void
    {
        $this->hostAdmin();

        $this->postJson('/api/v1/common/app-configurations/save', [
            'module' => 'general',
            'partner_id' => $this->partner->id,
            'anything' => 'x',
        ])->assertForbidden();

        $this->assertFalse(AppConfiguration::where('partner_id', $this->partner->id)->exists());
    }

    // -- Payment --------------------------------------------------------------

    #[Test]
    public function the_host_switches_a_partners_payment_provider(): void
    {
        $stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);
        $this->hostAdmin();

        $this->postJson('/api/v1/common/partner-payment-providers/status', [
            'partner_id' => $this->partner->id,
            'payment_provider_id' => $stripe->id,
            'status' => true,
        ])->assertOk();

        $this->assertTrue((bool) PartnerPaymentProvider::where('partner_id', $this->partner->id)->value('status'));
        $this->assertFalse(PartnerPaymentProvider::where('partner_id', $this->host->id)->exists());

        $this->getJson('/api/v1/common/partner-payment-providers?partner_id=' . $this->partner->id)->assertOk();
    }

    // -- Which partner ------------------------------------------------------

    /**
     * Every settings call naming $this->partner is refused, and none of its
     * settings change. The partner starts with an enabled Cash gateway, a
     * mailbox and no SMS or branding.
     */
    private function assertEveryCallNamingThePartnerIsForbidden(): void
    {
        $cash = PaymentProvider::create(['name' => 'Cash Payment', 'display_name' => 'Cash Payment', 'status' => true]);
        PartnerPaymentProvider::create(['partner_id' => $this->partner->id, 'payment_provider_id' => $cash->id, 'status' => true]);
        $mailbox = MailConfiguration::create([
            'partner_id' => $this->partner->id,
            'provider' => MailConfiguration::PROVIDER_SMTP,
            'from_address' => 'noreply@acme.test',
            'credentials' => ['smtp_host' => 'smtp.acme.test', 'smtp_port' => 587, 'smtp_username' => 'acme', 'smtp_password' => 'x'],
        ]);
        $theirs = ['partner_id' => $this->partner->id];
        $query = '?partner_id=' . $this->partner->id;

        // Payment
        $this->getJson('/api/v1/common/partner-payment-providers' . $query)->assertForbidden();
        $this->getJson("/api/v1/common/partner-payment-providers/{$cash->id}" . $query)->assertForbidden();
        $this->postJson('/api/v1/common/partner-payment-providers/status', $theirs + ['payment_provider_id' => $cash->id, 'status' => false])->assertForbidden();
        // Email
        $this->getJson('/api/v1/common/mail-configurations' . $query)->assertForbidden();
        $this->postJson('/api/v1/common/mail-configurations', $this->smtpPayload($theirs))->assertForbidden();
        $this->deleteJson("/api/v1/common/mail-configurations/{$mailbox->id}" . $query)->assertForbidden();
        // SMS
        $this->getJson('/api/v1/common/sms-configuration' . $query)->assertForbidden();
        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload($theirs))->assertForbidden();
        // Branding
        $this->postJson('/api/v1/common/app-configurations', $theirs + ['module' => AppConfiguration::MODULE_BRANDING])->assertForbidden();
        $this->postJson('/api/v1/common/app-configurations/save', $theirs + ['module' => AppConfiguration::MODULE_BRANDING, 'primary_color' => '#000000'])->assertForbidden();

        $this->assertTrue((bool) PartnerPaymentProvider::where('partner_id', $this->partner->id)->value('status'));
        $this->assertTrue(MailConfiguration::whereKey($mailbox->id)->exists());
        $this->assertSame(1, MailConfiguration::where('partner_id', $this->partner->id)->count());
        $this->assertFalse(SmsConfiguration::where('partner_id', $this->partner->id)->exists());
        $this->assertFalse(AppConfiguration::where('partner_id', $this->partner->id)->exists());
    }

    #[Test]
    public function a_partner_cannot_read_or_change_another_partners_settings(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner(), self::ROUTE_PERMISSIONS));

        $this->assertEveryCallNamingThePartnerIsForbidden();
    }

    #[Test]
    public function a_non_host_partner_cannot_name_another_partner_even_with_the_permission(): void
    {
        $this->actingAsUser($this->makePartnerAdmin(
            $this->makeWhiteLabelPartner(),
            [...self::ROUTE_PERMISSIONS, self::ON_BEHALF_PERMISSION]
        ));

        $this->assertEveryCallNamingThePartnerIsForbidden();
    }

    #[Test]
    public function a_host_user_without_the_permission_cannot_name_another_partner(): void
    {
        $this->hostAdmin(mayManagePartners: false);

        $this->assertEveryCallNamingThePartnerIsForbidden();
    }

    #[Test]
    public function a_partner_may_name_its_own_partner(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner, self::ROUTE_PERMISSIONS));

        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload(['partner_id' => $this->partner->id]))->assertOk();
        $this->getJson('/api/v1/common/partner-payment-providers?partner_id=' . $this->partner->id)->assertOk();

        $this->assertTrue(SmsConfiguration::where('partner_id', $this->partner->id)->exists());
    }

    #[Test]
    public function an_unknown_partner_is_not_found(): void
    {
        $this->hostAdmin();

        $this->getJson('/api/v1/common/sms-configuration?partner_id=999999')->assertNotFound();
    }

    #[Test]
    public function without_partner_id_the_host_still_edits_its_own_settings(): void
    {
        $this->hostAdmin();

        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload())->assertOk();

        $this->assertTrue(SmsConfiguration::where('partner_id', $this->host->id)->exists());
        $this->assertFalse(SmsConfiguration::where('partner_id', $this->partner->id)->exists());
    }
}
