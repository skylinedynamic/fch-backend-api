<?php

namespace Tests\Feature\EmbedForm;

use App\Mail\EmbedForm\NewSubmissionMail;
use App\Models\Setting\MailConfiguration;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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
 *   Sender    - every partner, White-Label included, is mailed the same way:
 *               through its own verified mail configuration, otherwise the
 *               platform fallback kept by the host (still under the partner's
 *               own sender name - see MailSenderName). The notice goes to the
 *               partner itself, so a white-label partner whose own settings are
 *               missing or unverified must still hear about its applications.
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

        // The host and the platform fallback are configured, so any fallback
        // would succeed and be visible - which is what makes the white-label
        // cases below meaningful.
        $this->mailConfig($this->host);
        $this->mailConfig(null);
    }

    #[Test]
    public function the_partners_email_column_is_the_address_used(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, [
            'email' => 'partner-column@example.test',
        ]);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('partner-column@example.test'));
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

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('partner-column@example.test'));
        Mail::assertNotQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('stale-form-inbox@example.test'));
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

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('partner-column@example.test'));
        Mail::assertNotQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('contact-person@example.test'));
    }

    #[Test]
    public function a_white_label_partner_without_its_own_mail_config_is_mailed_through_the_fallback(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('wl@example.test') && !$mail->hostCopy);
    }

    #[Test]
    public function a_white_label_partner_whose_config_is_not_verified_is_mailed_through_the_fallback(): void
    {
        // Saved SMTP settings that never passed verification - the case a
        // partner reported: the host was mailed, the partner was not.
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);
        $this->mailConfig($partner, ['verified' => '0']);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('wl@example.test') && !$mail->hostCopy);
    }

    #[Test]
    public function a_white_label_partner_with_its_own_mail_config_does_send(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);
        $this->mailConfig($partner);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('wl@example.test'));
    }

    #[Test]
    public function a_white_label_partner_whose_config_is_incomplete_is_mailed_through_the_fallback(): void
    {
        // A row exists as soon as the settings screen is opened, so "has rows" is
        // not the same as "can connect" - the fallback covers it.
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);
        $this->mailConfig($partner, ['smtp_host' => '', 'smtp_username' => '']);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('wl@example.test'));
    }

    #[Test]
    public function a_white_label_partner_who_has_not_enabled_its_config_is_mailed_through_the_fallback(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'email' => 'wl@example.test',
        ]);
        $this->mailConfig($partner, ['enable_custom_configuration' => '0']);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('wl@example.test'));
    }

    #[Test]
    public function a_certified_partner_without_its_own_config_still_sends(): void
    {
        // It trades under the host's brand, so the host's mailbox is correct.
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, [
            'email' => 'certified@example.test',
        ]);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('certified@example.test'));
    }

    #[Test]
    public function the_host_partners_own_form_still_sends(): void
    {
        // The host row carries partner_type = White-Label, so a type-first check
        // would silence the platform's own notifications.
        $this->notify($this->host);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo($this->host->email));
    }

    #[Test]
    public function a_partner_with_no_address_gets_no_mail_and_the_host_still_does(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, ['email' => null]);

        $this->notify($partner);

        // Only the host's copy goes out.
        Mail::assertQueued(NewSubmissionMail::class, 1);
        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo($this->host->email));
    }

    // ── The bell ──────────────────────────────────────────────────────

    #[Test]
    public function the_partner_is_told_in_the_bell_with_a_link_to_the_application(): void
    {
        Notification::fake();
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED);
        $login = $this->loginFor($partner);

        $submission = $this->notify($partner);

        Notification::assertSentTo($login, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'partner.embed_form.submitted'
            && $n->toArray($login)['body'] === 'Johnathan Smithfield submitted your embedded application form.'
            && $n->toArray($login)['url'] === "/embed-form/loan-applications/{$submission->id}");
    }

    #[Test]
    public function a_white_label_partner_without_mail_config_gets_the_bell_and_the_email(): void
    {
        Notification::fake();
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['email' => 'wl@example.test']);
        $login = $this->loginFor($partner);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('wl@example.test'));
        Notification::assertSentTo($login, DynamicNotification::class);
    }

    #[Test]
    public function a_partner_with_no_address_still_gets_the_bell(): void
    {
        Notification::fake();
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, ['email' => null]);
        $login = $this->loginFor($partner);

        $this->notify($partner);

        Notification::assertSentTo($login, DynamicNotification::class);
    }

    // ── Who is told, by which channels ─────────────────────────────────
    //
    //   White-label partner's form  partner: bell, push, mail and SMS (the SMS
    //                               only through its own active Twilio account)
    //                               host: bell, push and mail
    //   Certified partner's form    partner and host: bell, push and mail
    //   Host's own form             host: bell, push and mail, once

    #[Test]
    public function a_white_label_partner_is_also_texted(): void
    {
        Notification::fake();
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['email' => 'wl@example.test']);
        $login = $this->loginFor($partner);

        $submission = $this->notify($partner);

        Notification::assertSentTo($login, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'partner.embed_form.submitted_white_label'
            && $this->channels($n, $login) === ['database', 'broadcast', 'sms']
            && $n->toArray($login)['url'] === "/embed-form/loan-applications/{$submission->id}");
    }

    #[Test]
    public function the_host_is_told_about_a_white_label_partners_application_without_a_text(): void
    {
        Notification::fake();
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['email' => 'wl@example.test', 'company_name' => 'Acme Lending']);
        $this->mailConfig($partner);
        $hostLogin = $this->loginFor($this->host);

        $submission = $this->notify($partner);

        Notification::assertSentTo($hostLogin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'host.embed_form.submitted'
            && $this->channels($n, $hostLogin) === ['database', 'broadcast']
            && $n->toArray($hostLogin)['body'] === "Johnathan Smithfield submitted Acme Lending's embedded application form."
            && $n->toArray($hostLogin)['url'] === "/embed-form/loan-applications/{$submission->id}");
        $this->assertHostCopyMailed();
        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('wl@example.test') && !$mail->hostCopy);
    }

    #[Test]
    public function a_certified_partner_and_the_host_are_told_without_a_text(): void
    {
        Notification::fake();
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, ['email' => 'certified@example.test']);
        $login = $this->loginFor($partner);
        $hostLogin = $this->loginFor($this->host);

        $this->notify($partner);

        Notification::assertSentTo($login, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'partner.embed_form.submitted'
            && $this->channels($n, $login) === ['database', 'broadcast']);
        Notification::assertSentTo($hostLogin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'host.embed_form.submitted'
            && $this->channels($n, $hostLogin) === ['database', 'broadcast']);
        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo('certified@example.test'));
        $this->assertHostCopyMailed();
    }

    #[Test]
    public function the_hosts_own_form_tells_the_host_once_without_a_text(): void
    {
        Notification::fake();
        $hostLogin = $this->loginFor($this->host);

        $this->notify($this->host);

        Notification::assertSentToTimes($hostLogin, DynamicNotification::class, 1);
        Notification::assertSentTo($hostLogin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'partner.embed_form.submitted'
            && $this->channels($n, $hostLogin) === ['database', 'broadcast']);
        Mail::assertQueued(NewSubmissionMail::class, 1);
    }

    #[Test]
    public function the_hosts_copy_leaves_under_the_hosts_own_mailbox_and_names_the_partner(): void
    {
        // Not the white-label partner's: the host's copy is the host's mail.
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['email' => 'wl@example.test', 'company_name' => 'Acme Lending']);
        $this->mailConfig($partner);

        $this->notify($partner);

        Mail::assertQueued(NewSubmissionMail::class, function (NewSubmissionMail $mail) {
            if (!$mail->hostCopy) {
                return false;
            }
            $mailPartner = (new \ReflectionProperty($mail, 'mailPartnerId'))->getValue($mail);

            return $mail->hasTo($this->host->email)
                && $mailPartner === $this->host->id
                && str_contains($mail->render(), "Acme Lending's embedded form");
        });
    }

    #[Test]
    public function every_notice_says_it_came_from_the_embedded_form(): void
    {
        // A "New loan application" bell or text could be any application; the
        // partner has to know it came through the embedded form.
        Notification::fake();
        $whiteLabel = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['email' => 'wl@example.test']);
        $this->mailConfig($whiteLabel);
        $certified = $this->partner(Partner::PARTNER_TYPE_CERTIFIED);
        $logins = [$this->loginFor($whiteLabel), $this->loginFor($certified), $this->loginFor($this->host)];

        $this->notify($whiteLabel);
        $this->notify($certified);
        $this->notify($this->host);

        foreach ($logins as $login) {
            Notification::assertSentTo($login, DynamicNotification::class, function (DynamicNotification $n) use ($login) {
                $bell = $n->toArray($login);

                return str_contains($bell['title'], 'Embedded Form')
                    && str_contains($bell['body'], 'embedded')
                    && str_contains($n->toSms($login), 'Embedded Form');
            });
        }
        Mail::assertQueued(NewSubmissionMail::class, fn (NewSubmissionMail $mail) => str_contains($mail->envelope()->subject, 'Embedded Form'));
        Mail::assertNotQueued(NewSubmissionMail::class, fn (NewSubmissionMail $mail) => !str_contains($mail->envelope()->subject, 'Embedded Form'));
    }

    #[Test]
    public function the_requested_amount_is_mailed_in_its_currency(): void
    {
        $this->notify($this->host, [], ['requested_amount' => 150000, 'currency' => 'GBP']);

        Mail::assertQueued(NewSubmissionMail::class, fn (NewSubmissionMail $mail) => str_contains($mail->render(), '£150,000.00')
            && !str_contains($mail->render(), '$150,000.00'));
    }

    /**
     * The channel names (database, broadcast, sms) the notification goes out on.
     * via() returns Laravel's channels - SmsChannel's class for `sms` - so they
     * are mapped back to the names config/notifications.php uses.
     */
    private function channels(DynamicNotification $notification, User $user): array
    {
        $names = array_flip(config('notifications.channels'));

        return array_map(fn ($channel) => $names[$channel] ?? $channel, $notification->via($user));
    }

    private function assertHostCopyMailed(): void
    {
        Mail::assertQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo($this->host->email) && $mail->hostCopy);
    }

    private function assertNoMailTo(string $email): void
    {
        Mail::assertNotQueued(NewSubmissionMail::class, fn ($mail) => $mail->hasTo($email));
    }

    private function loginFor(Partner $partner): User
    {
        return User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Partner Login',
            'email' => 'login-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'partner_id' => $partner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Drive the one method under test. sendNotificationEmail() is protected and
     * reached in production from storeSubmission(); calling it directly keeps
     * these cases off the whole submission payload.
     *
     * @param  array<string, mixed>  $configAttributes
     */
    private function notify(Partner $partner, array $configAttributes = [], array $submissionAttributes = []): EmbedFormSubmission
    {
        $config = EmbedFormConfig::create(array_merge([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Personal Loan Application',
            'is_active' => true,
        ], $configAttributes));

        $submission = EmbedFormSubmission::create(array_merge([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'form_config_id' => $config->id,
            'status' => EmbedFormSubmission::STATUS_NEW,
            'first_name' => 'Johnathan',
            'last_name' => 'Smithfield',
            'email' => 'applicant@example.test',
        ], $submissionAttributes));

        $service = new class extends EmbedFormService {
            public function notifyNow(EmbedFormSubmission $submission, EmbedFormConfig $config): void
            {
                $this->sendNotificationEmail($submission, $config);
            }
        };

        $service->notifyNow($submission, $config->fresh()->load('partner'));

        return $submission;
    }

    /**
     * A verified SMTP configuration - the partner's default, or with no partner
     * the platform fallback. `enable_custom_configuration` => '0' leaves it
     * saved but not the default; blank host/username leave it incomplete;
     * `verified` => '0' leaves it saved but never verified.
     *
     * @param  array<string, string>  $overrides
     */
    private function mailConfig(?Partner $partner, array $overrides = []): void
    {
        $values = array_merge([
            'enable_custom_configuration' => '1',
            'smtp_host' => 'smtp.example.test',
            'smtp_username' => 'mailer@example.test',
            'verified' => '1',
        ], $overrides);

        $config = MailConfiguration::create([
            'partner_id' => $partner?->id,
            'provider' => MailConfiguration::PROVIDER_SMTP,
            'from_address' => 'mail@example.test',
            'credentials' => [
                'smtp_host' => $values['smtp_host'] ?: null,
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_username' => $values['smtp_username'] ?: null,
                'smtp_password' => 'secret',
            ],
            'is_default' => $partner !== null && $values['enable_custom_configuration'] === '1',
        ]);

        if ($values['verified'] === '1') {
            $config->forceFill(['verified_at' => now(), 'verified_fingerprint' => $config->fingerprint()])->save();
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
