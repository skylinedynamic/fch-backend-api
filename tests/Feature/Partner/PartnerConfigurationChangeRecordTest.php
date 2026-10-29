<?php

namespace Tests\Feature\Partner;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Every change the host makes to a partner's settings from Partner profile ›
 * Configuration is recorded on that partner's activity log (visible to
 * everyone) and the partner is told in the bell (pushed live). Changes a
 * partner makes to its own settings, reads and refused saves record nothing.
 */
class PartnerConfigurationChangeRecordTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    private Partner $partner;

    private User $partnerLogin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner(['company_name' => 'FCH Host']);
        $this->partner = $this->makeWhiteLabelPartner(['company_name' => 'Acme Lending']);
        $this->partnerLogin = $this->makePartnerAdmin($this->partner);

        $this->actingAsUser($this->makePartnerAdmin($this->host, [
            'update email configuration settings',
            'update sms configuration settings',
            'manage partner configuration',
        ], ['name' => 'Hana Host']));
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

    private function logsFor(Partner $partner)
    {
        return ActivityLog::where('log_name', ActivityLog::LOG_TYPE_PARTNER_CONFIGURATION_UPDATE)
            ->get()
            ->filter(fn (ActivityLog $log) => (int) $log->partner_id === $partner->id);
    }

    /**
     * @param  string  $label  The area as the notification names it.
     * @param  string  $summary  What the activity log says was done.
     */
    private function assertRecorded(string $area, string $label, string $summary): void
    {
        $logs = $this->logsFor($this->partner);
        $this->assertCount(1, $logs, "one activity log for the {$area} change");

        $log = $logs->first();
        $this->assertSame(ActivityLog::VISIBILITY_EVERYONE, $log->visibility);
        $this->assertSame($area, $log->properties['area'] ?? null);
        $this->assertStringContainsString("Hana Host {$summary}", $log->description);

        Notification::assertSentTo($this->partnerLogin, DynamicNotification::class, function (DynamicNotification $n) use ($label) {
            $payload = $n->toArray($this->partnerLogin);

            return $n->typeKey === 'partner.configuration.updated'
                && str_contains($payload['body'], $label)
                && str_contains($payload['body'], 'FCH Host');
        });
    }

    #[Test]
    public function an_sms_change_is_logged_on_the_partner_and_pushed_to_it(): void
    {
        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload(['partner_id' => $this->partner->id]))->assertOk();

        $this->assertRecorded('sms', 'SMS configuration', 'updated the SMS configuration');
    }

    #[Test]
    public function an_email_change_is_logged_and_pushed(): void
    {
        $this->postJson('/api/v1/common/mail-configurations', [
            'partner_id' => $this->partner->id,
            'provider' => 'smtp',
            'from_address' => 'noreply@acme.test',
            'smtp_host' => 'smtp.acme.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'acme',
            'smtp_password' => 'smtp-secret',
        ])->assertOk();

        $this->assertRecorded('email', 'email configuration', 'saved the SMTP mail provider');
    }

    #[Test]
    public function a_branding_change_is_logged_and_pushed(): void
    {
        $this->postJson('/api/v1/common/app-configurations/save', [
            'module' => AppConfiguration::MODULE_BRANDING,
            'partner_id' => $this->partner->id,
            'primary_color' => '#123456',
        ])->assertOk();

        $this->assertRecorded('branding', 'branding', 'updated the branding');
    }

    #[Test]
    public function a_payment_change_is_logged_and_pushed(): void
    {
        $stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);

        $this->postJson('/api/v1/common/partner-payment-providers/status', [
            'partner_id' => $this->partner->id,
            'payment_provider_id' => $stripe->id,
            'status' => true,
        ])->assertOk();

        $this->assertRecorded('payment', 'payment settings', 'turned Stripe on');
    }

    #[Test]
    public function an_agreement_change_is_logged_and_pushed(): void
    {
        $this->postJson('/api/v1/common/agreement-templates/save', [
            'partner_id' => $this->partner->id,
            'slug' => 'borrower',
            'template_html' => '<p>Acme terms</p>',
        ])->assertOk();

        $this->assertRecorded('agreement', 'agreement', 'updated the borrower agreement');
    }

    #[Test]
    public function turning_an_agreement_off_is_logged_and_pushed(): void
    {
        $id = DB::table('agreement_templates')->insertGetId([
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'template_html' => '<p>Terms</p>',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/common/agreement-templates/update-status/{$id}", [
            'partner_id' => $this->partner->id,
            'is_active' => false,
        ])->assertOk();

        $this->assertRecorded('agreement', 'agreement', 'turned the borrower agreement off');
    }

    // -- What the entry says -------------------------------------------------

    private function onlyLog(): ActivityLog
    {
        $logs = $this->logsFor($this->partner);
        $this->assertNotEmpty($logs);

        return $logs->sortByDesc('id')->first();
    }

    #[Test]
    public function the_sms_entry_lists_each_changed_field_old_to_new_and_never_a_secret(): void
    {
        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload(['partner_id' => $this->partner->id, 'is_enabled' => false]))->assertOk();
        ActivityLog::query()->delete();

        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload([
            'partner_id' => $this->partner->id,
            'is_enabled' => true,
            'from_number' => '+15005550007',
            'auth_token' => 'a-new-secret-token',
        ]))->assertOk();

        $log = $this->onlyLog();
        $this->assertStringContainsString('updated the SMS configuration', $log->description);
        // The activity sidebar's "Show changes" table: field => {label, from, to}.
        $changes = $log->properties['changes'];
        $this->assertSame(['label' => 'Enabled', 'from' => 'No', 'to' => 'Yes'], $changes['is_enabled']);
        $this->assertSame(['label' => 'From number', 'from' => '+15005550006', 'to' => '+15005550007'], $changes['from_number']);
        $this->assertSame(['label' => 'Auth token', 'from' => null, 'to' => 'Changed'], $changes['auth_token']);
        $this->assertSame('Host Admin updated the SMS configuration.', str_replace('Hana Host', 'Host Admin', $log->description));

        // Credentials never reach the log.
        $stored = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('a-new-secret-token', $stored);
        $this->assertStringNotContainsString('secret-token', $log->description);
    }

    #[Test]
    public function the_mail_entries_say_what_was_done_to_which_provider(): void
    {
        $this->postJson('/api/v1/common/mail-configurations', [
            'partner_id' => $this->partner->id,
            'provider' => 'smtp',
            'from_address' => 'noreply@acme.test',
            'smtp_host' => 'smtp.acme.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'acme',
            'smtp_password' => 'smtp-secret',
        ])->assertOk();

        $saved = $this->onlyLog();
        $this->assertStringContainsString('saved the SMTP mail provider', $saved->description);
        $this->assertSame(['label' => 'SMTP Host', 'from' => null, 'to' => 'smtp.acme.test'], $saved->properties['changes']['smtp_host']);
        $this->assertSame(['label' => 'SMTP Password', 'from' => null, 'to' => 'Changed'], $saved->properties['changes']['smtp_password']);
        $this->assertStringNotContainsString('smtp-secret', json_encode($saved->getAttributes()));

        $id = \App\Models\Setting\MailConfiguration::where('partner_id', $this->partner->id)->value('id');
        $this->deleteJson("/api/v1/common/mail-configurations/{$id}?partner_id={$this->partner->id}")->assertOk();

        $this->assertStringContainsString('removed the SMTP mail provider', $this->onlyLog()->description);
    }

    #[Test]
    public function the_branding_entry_lists_the_changed_settings(): void
    {
        $this->postJson('/api/v1/common/app-configurations/save', [
            'module' => AppConfiguration::MODULE_BRANDING,
            'partner_id' => $this->partner->id,
            'primary_color' => '#123456',
            'site_name' => 'Acme',
        ])->assertOk();

        $log = $this->onlyLog();
        $this->assertStringContainsString('updated the branding', $log->description);
        $this->assertSame(['label' => 'Primary color', 'from' => null, 'to' => '#123456'], $log->properties['changes']['primary_color']);
        $this->assertSame(['label' => 'Site name', 'from' => null, 'to' => 'Acme'], $log->properties['changes']['site_name']);
    }

    #[Test]
    public function the_payment_entry_says_which_provider_was_turned_on(): void
    {
        $stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);

        $this->postJson('/api/v1/common/partner-payment-providers/status', [
            'partner_id' => $this->partner->id,
            'payment_provider_id' => $stripe->id,
            'status' => true,
        ])->assertOk();

        $this->assertStringContainsString('turned Stripe on', $this->onlyLog()->description);
    }

    #[Test]
    public function a_partners_change_to_its_own_settings_records_nothing(): void
    {
        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload())->assertOk();

        $this->assertCount(0, ActivityLog::where('log_name', ActivityLog::LOG_TYPE_PARTNER_CONFIGURATION_UPDATE)->get());
        Notification::assertNotSentTo($this->partnerLogin, DynamicNotification::class);
    }

    #[Test]
    public function reading_and_a_refused_save_record_nothing(): void
    {
        $this->getJson('/api/v1/common/sms-configuration?partner_id=' . $this->partner->id)->assertOk();
        $this->postJson('/api/v1/common/sms-configuration', $this->smsPayload([
            'partner_id' => $this->partner->id, 'account_sid' => null, 'auth_token' => null,
        ]))->assertUnprocessable();

        $this->assertCount(0, $this->logsFor($this->partner));
        Notification::assertNotSentTo($this->partnerLogin, DynamicNotification::class);
    }
}
