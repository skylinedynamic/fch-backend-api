<?php

namespace Tests\Feature\ActivityLog;

use App\Mail\ActivityLog\ActivityLogMail;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\ActivityLog\ActivityEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\MailConfiguration\MailConfigurationFixtures;
use Tests\TestCase;

/**
 * New Email, for the host: who the email goes out from. By default the mail
 * settings of the partner the client belongs to; a switch sends it from the
 * host's own instead. Either one without settings of its own that can send
 * goes out from the fallback. Only the host sees or chooses this; a partner's
 * mail goes out as before.
 */
#[CoversClass(ActivityEmailSender::class)]
class ActivityEmailSenderTest extends TestCase
{
    use RefreshDatabase;
    use MailConfigurationFixtures;

    private const URL = '/api/v1/common/activity-logs/email-sender';

    private User $hostAdmin;
    private Borrower $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
        $this->hostAdmin = $this->owner($this->host, false);
        $this->client = Borrower::create([
            'partner_id' => $this->whiteLabel->id,
            'first_name' => 'Nell', 'last_name' => 'Client',
            'email' => 'client-' . uniqid() . '@example.test',
        ]);
    }

    private function fallback(): void
    {
        $this->markVerified($this->smtpConfig(null, ['is_default' => true]));
    }

    private function sendersFor(?int $partnerId)
    {
        return $this->getJson(self::URL . ($partnerId ? "?partner_id={$partnerId}" : ''));
    }

    // ── What the host is shown ────────────────────────────────────────

    #[Test]
    public function the_clients_partner_is_the_default_with_its_own_address(): void
    {
        $this->fallback();
        $this->markVerified($this->smtpConfig($this->whiteLabel, ['is_default' => true]));
        $this->actingAsUser($this->hostAdmin);

        $this->sendersFor($this->whiteLabel->id)
            ->assertOk()
            ->assertJsonPath('message', __('activity_log.email_sender'))
            ->assertJsonPath('data.default', ActivityLog::EMAIL_SENDER_PARTNER)
            ->assertJsonPath('data.options.partner.partner_id', $this->whiteLabel->id)
            ->assertJsonPath('data.options.partner.name', 'Acme Capital')
            ->assertJsonPath('data.options.partner.address', 'mail@partner' . $this->whiteLabel->id . '.test')
            ->assertJsonPath('data.options.partner.uses_fallback', false)
            ->assertJsonPath('data.options.host.partner_id', $this->host->id)
            ->assertJsonPath('data.options.host.name', 'Four Corner Funding');
    }

    #[Test]
    public function the_host_option_is_the_hosts_own_address(): void
    {
        $this->fallback();
        $this->markVerified($this->smtpConfig($this->host, ['is_default' => true]));
        $this->actingAsUser($this->hostAdmin);

        $this->sendersFor($this->whiteLabel->id)
            ->assertOk()
            ->assertJsonPath('data.options.host.address', 'mail@partner' . $this->host->id . '.test')
            ->assertJsonPath('data.options.host.uses_fallback', false);
    }

    #[Test]
    public function one_without_mail_settings_of_its_own_shows_the_fallback_address(): void
    {
        $this->fallback();
        $this->actingAsUser($this->hostAdmin);

        $this->sendersFor($this->whiteLabel->id)
            ->assertOk()
            ->assertJsonPath('data.options.partner.address', 'mail@fallback.test')
            ->assertJsonPath('data.options.partner.uses_fallback', true)
            ->assertJsonPath('data.options.host.address', 'mail@fallback.test')
            ->assertJsonPath('data.options.host.uses_fallback', true);
    }

    #[Test]
    public function the_partners_branded_site_name_comes_before_its_company_name(): void
    {
        $this->fallback();
        AppConfiguration::create([
            'partner_id' => $this->whiteLabel->id, 'module' => AppConfiguration::MODULE_BRANDING,
            'key' => 'site_name', 'value' => 'Acme Funding Portal',
        ]);
        $this->actingAsUser($this->hostAdmin);

        $this->sendersFor($this->whiteLabel->id)->assertOk()->assertJsonPath('data.options.partner.name', 'Acme Funding Portal');
    }

    #[Test]
    public function with_nothing_that_can_send_there_is_no_address(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->sendersFor($this->whiteLabel->id)
            ->assertOk()
            ->assertJsonPath('data.options.partner.address', null)
            ->assertJsonPath('data.options.host.address', null);
    }

    #[Test]
    public function a_host_record_has_only_the_host_option(): void
    {
        $this->fallback();
        $this->actingAsUser($this->hostAdmin);

        foreach ([null, $this->host->id] as $partnerId) {
            $this->sendersFor($partnerId)
                ->assertOk()
                ->assertJsonPath('data.default', ActivityLog::EMAIL_SENDER_HOST)
                ->assertJsonPath('data.options.partner', null);
        }
    }

    #[Test]
    public function only_the_host_is_told(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel, false));

        $this->sendersFor($this->whiteLabel->id)
            ->assertForbidden()
            ->assertJsonPath('message', __('activity_log.email_sender_host_only'));
    }

    #[Test]
    public function an_unknown_partner_is_refused(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->sendersFor(999999)->assertStatus(422);
    }

    #[Test]
    public function a_guest_is_refused(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    #[Test]
    public function the_messages_read_as_sentences_not_keys(): void
    {
        foreach (['email_sender', 'email_sender_host_only'] as $key) {
            $this->assertNotSame("activity_log.{$key}", __("activity_log.{$key}"));
        }
    }

    // ── What the email is sent with ───────────────────────────────────

    private function sendEmail(array $extra = []): void
    {
        $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => ActivityLog::TYPE_EMAIL,
            'borrower_id' => $this->client->id,
            'subject' => 'Statements',
            'description' => '<p>Hello</p>',
            'to_emails' => $this->client->email,
        ] + $extra)->assertSuccessful();
    }

    private function assertSentAs(?int $partnerId): void
    {
        Mail::assertQueued(ActivityLogMail::class, fn (ActivityLogMail $mail) => $mail->sendingPartnerId() === $partnerId);
    }

    #[Test]
    public function a_hosts_email_on_a_client_goes_out_with_the_clients_partners_settings_by_default(): void
    {
        Mail::fake();
        $this->actingAsUser($this->hostAdmin);

        $this->sendEmail();

        $this->assertSentAs($this->whiteLabel->id);
    }

    #[Test]
    public function the_switch_sends_it_with_the_hosts_settings(): void
    {
        Mail::fake();
        $this->actingAsUser($this->hostAdmin);

        $this->sendEmail(['send_from' => ActivityLog::EMAIL_SENDER_HOST]);

        $this->assertSentAs($this->host->id);
    }

    #[Test]
    public function a_partner_cannot_choose_its_mail_goes_out_as_before(): void
    {
        Mail::fake();
        $this->actingAsUser($this->owner($this->whiteLabel, false));

        $this->sendEmail(['send_from' => ActivityLog::EMAIL_SENDER_HOST]);

        // getConfigPartner(): a White-Label partner sends with its own.
        $this->assertSentAs($this->whiteLabel->id);
    }

    #[Test]
    public function an_unknown_sender_is_refused(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => ActivityLog::TYPE_EMAIL, 'borrower_id' => $this->client->id,
            'subject' => 'S', 'description' => 'B', 'to_emails' => 'a@example.test', 'send_from' => 'someone',
        ])->assertStatus(422)->assertJsonValidationErrors('send_from');
    }
}
