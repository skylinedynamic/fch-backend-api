<?php

namespace Tests\Feature\MailConfiguration;

use App\Mail\Setting\MailConfigurationVerificationMail;
use App\Models\Setting\MailConfiguration;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A provider is verified by a code sent through that provider's own settings
 * to the signed-in user, who types it back.
 */
class MailVerificationTest extends TestCase
{
    use RefreshDatabase;
    use MailConfigurationFixtures;

    private const URL = '/api/v1/common/mail-configurations';

    private User $user;
    private MailConfiguration $smtp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
        $this->user = $this->actingAsUser($this->owner($this->whiteLabel));
        $this->smtp = $this->smtpConfig($this->whiteLabel);
    }

    /**
     * Sends a code and returns it, read from the mail that carried it.
     */
    private function sendCode(?MailConfiguration $config = null): string
    {
        $config ??= $this->smtp;
        $code = null;

        Mail::fake();
        $this->postJson(self::URL . "/{$config->id}/send-code")->assertOk();

        Mail::assertSent(MailConfigurationVerificationMail::class, function (MailConfigurationVerificationMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    #[Test]
    public function the_code_goes_to_the_signed_in_user_through_the_settings_being_verified(): void
    {
        Mail::fake();

        $response = $this->postJson(self::URL . "/{$this->smtp->id}/send-code")->assertOk();

        Mail::assertSent(MailConfigurationVerificationMail::class, function (MailConfigurationVerificationMail $mail) {
            return $mail->hasTo($this->user->email)
                && $mail->configurationId() === $this->smtp->id
                && preg_match('/^\d{6}$/', $mail->code) === 1;
        });
        // The code only ever travels by mail.
        $this->assertDoesNotMatchRegularExpression('/\b\d{6}\b/', json_encode($response->json('data')));
    }

    #[Test]
    public function the_right_code_verifies_the_provider(): void
    {
        $code = $this->sendCode();

        $this->postJson(self::URL . "/{$this->smtp->id}/verify", ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.verified_to', $this->user->email);

        $this->smtp->refresh();
        $this->assertTrue($this->smtp->isVerified());
        $this->assertSame($this->user->id, $this->smtp->verified_by);
    }

    #[Test]
    public function the_first_verified_provider_becomes_the_default(): void
    {
        $code = $this->sendCode();

        $this->postJson(self::URL . "/{$this->smtp->id}/verify", ['code' => $code])
            ->assertJsonPath('data.is_default', true);
    }

    #[Test]
    public function verifying_another_provider_leaves_the_default_alone(): void
    {
        $this->markVerified($this->smtp)->update(['is_default' => true]);
        $ses = $this->sesConfig($this->whiteLabel);

        $code = $this->sendCode($ses);
        $this->postJson(self::URL . "/{$ses->id}/verify", ['code' => $code])
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.is_default', false);

        $this->assertTrue($this->smtp->refresh()->is_default);
    }

    #[Test]
    public function a_wrong_code_is_refused(): void
    {
        $code = $this->sendCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson(self::URL . "/{$this->smtp->id}/verify", ['code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertFalse($this->smtp->refresh()->isVerified());
    }

    #[Test]
    public function too_many_wrong_codes_use_the_code_up(): void
    {
        $code = $this->sendCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL . "/{$this->smtp->id}/verify", ['code' => $wrong])->assertUnprocessable();
        }

        $this->postJson(self::URL . "/{$this->smtp->id}/verify", ['code' => $code])->assertUnprocessable();
        $this->assertFalse($this->smtp->refresh()->isVerified());
    }

    #[Test]
    public function a_code_expires_after_ten_minutes(): void
    {
        $code = $this->sendCode();

        $this->travel(11)->minutes();

        $this->postJson(self::URL . "/{$this->smtp->id}/verify", ['code' => $code])->assertUnprocessable();
    }

    #[Test]
    public function a_new_code_waits_a_minute(): void
    {
        $this->sendCode();

        $this->postJson(self::URL . "/{$this->smtp->id}/send-code")->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson(self::URL . "/{$this->smtp->id}/send-code")->assertOk();
    }

    #[Test]
    public function a_code_sent_for_old_settings_does_not_verify_new_ones(): void
    {
        $code = $this->sendCode();

        $this->smtp->credentials = array_merge($this->smtp->credentials, ['smtp_host' => 'smtp.other.test']);
        $this->smtp->save();

        $this->postJson(self::URL . "/{$this->smtp->id}/verify", ['code' => $code])->assertUnprocessable();
        $this->assertFalse($this->smtp->refresh()->isVerified());
    }

    #[Test]
    public function incomplete_settings_get_no_code(): void
    {
        Mail::fake();
        $this->smtp->update(['credentials' => ['smtp_host' => 'smtp.acme.test']]);

        $this->postJson(self::URL . "/{$this->smtp->id}/send-code")->assertUnprocessable();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_code_that_cannot_be_sent_says_so_plainly_and_logs_why(): void
    {
        Log::spy();
        Mail::partialMock()->shouldReceive('send')->andThrow(new \RuntimeException('535 Authentication failed for mailer'));

        $response = $this->postJson(self::URL . "/{$this->smtp->id}/send-code")->assertUnprocessable();

        $this->assertSame(__('mail_config.code_not_sent'), $response->json('message'));
        $this->assertStringNotContainsString('535', $response->getContent());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($context['error'] ?? '', '535'));
    }
}
