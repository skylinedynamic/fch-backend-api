<?php

namespace Tests\Feature\EmbedForm;

use App\Mail\EmbedForm\NewSubmissionMail;
use App\Models\Setting\EmailConfiguration;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who gets told about a new public form submission, and when nobody does.
 *
 * Two rules, both about not putting a tenant's mail through someone else's
 * mailbox or inbox:
 *
 *   Recipient - the form's own notification_email, otherwise the partner's
 *               `email` column. NOT contact_person_email: that is a contact for
 *               the partner's staff, not the address the platform notifies.
 *   Sender    - a White-Label partner sends through its own mail configuration
 *               or not at all. The host's credentials are not a fallback for it,
 *               because mail leaving under the host's mailbox is exactly what
 *               the white-label tier exists to prevent. A Certified or host
 *               partner still falls back to the host's configuration.
 *
 * Mail is faked, so nothing here depends on a transport: the assertions are
 * about the decision to send and the address chosen.
 */
class EmbedFormNotificationMailTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        // The host is configured, so any host fallback would succeed and be
        // visible - which is what makes the white-label cases below meaningful.
        $this->mailConfig($this->host);
    }

    #[Test]
    public function the_partners_email_column_is_the_address_used(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, [
            'email' => 'partner-column@example.test',
        ]);

        $this->notify($partner);

        Mail::assertSent(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('partner-column@example.test'));
    }

    #[Test]
    public function a_stored_notification_email_is_ignored(): void
    {
        // The settings screen no longer offers the field, but rows saved before
        // it was removed still carry a value - and it must not redirect mail.
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, [
            'email' => 'partner-column@example.test',
        ]);

        $this->notify($partner, ['notification_email' => 'stale-form-inbox@example.test']);

        Mail::assertSent(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('partner-column@example.test'));
        Mail::assertNotSent(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('stale-form-inbox@example.test'));
    }

    #[Test]
    public function the_contact_person_email_is_never_used(): void
    {
        // It used to sit between the two above, so a partner whose contact person
        // differs from the account holder had notifications going to the wrong
        // person entirely.
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, [
            'email' => 'partner-column@example.test',
            'contact_person_email' => 'contact-person@example.test',
        ]);

        $this->notify($partner);

        Mail::assertSent(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('partner-column@example.test'));
        Mail::assertNotSent(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('contact-person@example.test'));
    }

    #[Test]
    public function a_white_label_partner_without_its_own_mail_config_sends_nothing(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);

        $this->notify($partner);

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_white_label_partner_with_its_own_mail_config_does_send(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);
        $this->mailConfig($partner);

        $this->notify($partner);

        Mail::assertSent(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('wl@example.test'));
    }

    #[Test]
    public function a_white_label_partner_whose_config_is_incomplete_sends_nothing(): void
    {
        // A row exists as soon as the settings screen is opened, so "has rows" is
        // not the same as "can connect".
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);
        $this->mailConfig($partner, ['smtp_host' => '', 'smtp_username' => '']);

        $this->notify($partner);

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_white_label_partner_who_has_not_enabled_its_config_sends_nothing(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);
        $this->mailConfig($partner, ['enable_custom_configuration' => '0']);

        $this->notify($partner);

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_certified_partner_without_its_own_config_still_sends(): void
    {
        // It trades under the host's brand, so the host's mailbox is correct.
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, [
            'email' => 'certified@example.test',
        ]);

        $this->notify($partner);

        Mail::assertSent(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('certified@example.test'));
    }

    #[Test]
    public function the_host_partners_own_form_still_sends(): void
    {
        // The host row carries partner_type = White-Label, so a type-first check
        // would silence the platform's own notifications.
        $this->notify($this->host);

        Mail::assertSent(NewSubmissionMail::class, fn ($mail) => $mail->hasTo($this->host->email));
    }

    #[Test]
    public function a_partner_with_no_address_at_all_sends_nothing(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, ['email' => null]);

        $this->notify($partner);

        Mail::assertNothingSent();
    }

    /**
     * Drive the one method under test. sendNotificationEmail() is protected and
     * reached in production from storeSubmission(); calling it directly keeps
     * these cases off the whole submission payload.
     *
     * @param  array<string, mixed>  $configAttributes
     */
    private function notify(Partner $partner, array $configAttributes = []): void
    {
        $config = EmbedFormConfig::create(array_merge([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Personal Loan Application',
            'is_active' => true,
        ], $configAttributes));

        $submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'form_config_id' => $config->id,
            'status' => EmbedFormSubmission::STATUS_NEW,
            'first_name' => 'Johnathan',
            'last_name' => 'Smithfield',
            'email' => 'applicant@example.test',
        ]);

        $service = new class extends EmbedFormService {
            public function notifyNow(EmbedFormSubmission $submission, EmbedFormConfig $config): void
            {
                $this->sendNotificationEmail($submission, $config);
            }
        };

        $service->notifyNow($submission, $config->fresh()->load('partner'));
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function mailConfig(Partner $partner, array $overrides = []): void
    {
        $values = array_merge([
            'enable_custom_configuration' => '1',
            'email_provider' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_username' => 'mailer@example.test',
            'smtp_password' => 'secret',
        ], $overrides);

        foreach ($values as $key => $value) {
            EmailConfiguration::create([
                'partner_id' => $partner->id,
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function partner(string $type, array $attributes = []): Partner
    {
        return Partner::create(array_merge([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => $type,
            'company_name' => 'Partner ' . uniqid(),
            'full_name' => 'Partner Contact',
            'email' => 'partner-' . uniqid() . '@example.test',
        ], $attributes));
    }
}
