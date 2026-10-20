<?php

namespace Tests\Feature\EmailChange;

use App\Http\Controllers\Api\V1\Global\Account\EmailChangeLinkController;
use App\Mail\Account\EmailChangeConfirmationMail;
use App\Mail\Account\EmailChangedMail;
use App\Mail\Account\EmailChangeRequestedMail;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FollowsEmailChangeLinks;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The email-change links open on the account's own portal, not on the API.
 * The portal page reads and acts on the link through the API's JSON
 * endpoints, passing the link's expires and signature along unchanged.
 */
#[CoversClass(EmailChangeLinkController::class)]
#[CoversClass(EmailChangeService::class)]
class EmailChangePortalLinksTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;
    use FollowsEmailChangeLinks;

    private Partner $whiteLabel;
    private EmailChangeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['app.frontend_partner_url' => 'partner.example.com', 'app.frontend_borrower_url' => 'app.example.com']);
        $this->makeHostPartner();
        $this->whiteLabel = $this->makeWhiteLabelPartner([
            'active_custom_url' => true,
            'partner_domain' => 'admin.wl.com',
            'partner_subdomain' => 'wl.fourcornerfunding.com',
            'borrower_domain' => 'client.wl.com',
        ]);
        $this->service = app(EmailChangeService::class);
    }

    private function sentUrl(string $mailable, string $property): string
    {
        $url = null;
        Mail::assertSent($mailable, function ($mail) use ($property, &$url) {
            $url = $mail->{$property};
            return true;
        });

        return $url;
    }

    private function requestChange(User $user, string $newEmail = 'new@example.test'): void
    {
        $this->service->request($user, $newEmail);
    }

    // ---- Where the links point -------------------------------------------------

    #[Test]
    public function a_white_label_clients_links_open_on_its_partners_borrower_domain(): void
    {
        $client = $this->makeBorrowerUser($this->whiteLabel);
        $this->requestChange($client);

        $confirm = $this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl');
        $cancel = $this->sentUrl(EmailChangeRequestedMail::class, 'cancelUrl');

        $this->assertStringStartsWith("https://client.wl.com/email-change/confirm/{$client->id}/", $confirm);
        $this->assertStringStartsWith("https://client.wl.com/email-change/cancel/{$client->id}/", $cancel);
        $this->assertStringContainsString('expires=', $confirm);
        $this->assertStringContainsString('signature=', $confirm);
    }

    #[Test]
    public function a_white_label_partners_own_login_gets_links_on_its_partner_domain(): void
    {
        $admin = $this->makePartnerAdmin($this->whiteLabel);
        $this->requestChange($admin);

        $this->assertStringStartsWith("https://admin.wl.com/email-change/confirm/{$admin->id}/", $this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl'));
    }

    #[Test]
    public function the_hosts_team_gets_links_on_the_partner_portal(): void
    {
        $hostAdmin = $this->makePartnerAdmin(Partner::host());
        $this->requestChange($hostAdmin);

        $this->assertStringStartsWith("https://partner.example.com/email-change/confirm/{$hostAdmin->id}/", $this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl'));
    }

    #[Test]
    public function no_link_points_at_the_api(): void
    {
        $this->requestChange($this->makePartnerAdmin($this->whiteLabel));

        $this->assertStringNotContainsString('/api/', $this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl'));
        $this->assertStringNotContainsString('/api/', $this->sentUrl(EmailChangeRequestedMail::class, 'cancelUrl'));
    }

    // ---- Confirm ---------------------------------------------------------------

    #[Test]
    public function the_portal_reads_a_confirmation_link_before_anything_changes(): void
    {
        $admin = $this->makePartnerAdmin($this->whiteLabel);
        $this->requestChange($admin);

        $this->getJson($this->emailChangeApiUrl($this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl')))
            ->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.action', 'confirm')
            ->assertJsonPath('data.needs_password', true)
            ->assertJsonPath('data.title', __('email_change.confirm_title'));

        $this->assertNotSame('new@example.test', $admin->fresh()->email);
    }

    #[Test]
    public function confirming_with_the_password_changes_the_email(): void
    {
        $admin = $this->makePartnerAdmin($this->whiteLabel);
        $this->requestChange($admin);

        $this->postJson($this->emailChangeApiUrl($this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl')), ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.state', 'changed')
            ->assertJsonPath('data.login_url', 'https://admin.wl.com/login');

        $this->assertSame('new@example.test', $admin->fresh()->email);
    }

    #[Test]
    public function a_wrong_password_changes_nothing_and_says_so(): void
    {
        $admin = $this->makePartnerAdmin($this->whiteLabel);
        $this->requestChange($admin);

        $this->postJson($this->emailChangeApiUrl($this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl')), ['password' => 'a-guess'])
            ->assertOk()
            ->assertJsonPath('data.state', 'wrong_password')
            ->assertJsonPath('data.needs_password', true)
            ->assertJsonPath('data.message', __('email_change.wrong_password'));

        $this->assertNotSame('new@example.test', $admin->fresh()->email);
    }

    #[Test]
    public function a_tampered_or_expired_link_is_refused(): void
    {
        $admin = $this->makePartnerAdmin($this->whiteLabel);
        $this->requestChange($admin);
        $url = $this->emailChangeApiUrl($this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl'));

        $this->getJson(preg_replace('/signature=[0-9a-f]+/', 'signature=' . str_repeat('0', 64), $url))
            ->assertOk()->assertJsonPath('data.state', 'invalid');

        $this->travel(EmailChangeService::LINK_LIFETIME_HOURS + 1)->hours();
        $this->postJson($url, ['password' => 'password'])->assertOk()->assertJsonPath('data.state', 'invalid');
        $this->assertNotSame('new@example.test', $admin->fresh()->email);
    }

    // ---- Cancel and undo ------------------------------------------------------

    #[Test]
    public function the_old_address_cancels_a_pending_change_from_its_link(): void
    {
        $admin = $this->makePartnerAdmin($this->whiteLabel);
        $this->requestChange($admin);
        $url = $this->emailChangeApiUrl($this->sentUrl(EmailChangeRequestedMail::class, 'cancelUrl'));

        $this->getJson($url)->assertOk()->assertJsonPath('data.state', 'ready')->assertJsonPath('data.needs_password', false);
        $this->postJson($url)->assertOk()->assertJsonPath('data.state', 'cancelled');

        $this->assertNull($admin->fresh()->pending_email);
    }

    #[Test]
    public function the_old_address_undoes_a_confirmed_change_from_its_link(): void
    {
        $admin = $this->makePartnerAdmin($this->whiteLabel);
        $oldEmail = $admin->email;
        $this->requestChange($admin);
        $this->postJson($this->emailChangeApiUrl($this->sentUrl(EmailChangeConfirmationMail::class, 'confirmationUrl')), ['password' => 'password'])
            ->assertJsonPath('data.state', 'changed');

        $undo = $this->sentUrl(EmailChangedMail::class, 'revertUrl');
        $this->assertStringStartsWith("https://admin.wl.com/email-change/undo/{$admin->id}/", $undo);

        $this->getJson($this->emailChangeApiUrl($undo))->assertOk()->assertJsonPath('data.state', 'ready');
        $this->postJson($this->emailChangeApiUrl($undo))->assertOk()->assertJsonPath('data.state', 'reverted');

        $this->assertSame($oldEmail, $admin->fresh()->email);
    }

    // ---- Links sent before this change ---------------------------------------

    #[Test]
    public function a_link_already_sent_to_the_api_still_opens_its_page(): void
    {
        $admin = $this->makePartnerAdmin($this->whiteLabel);
        $this->requestChange($admin);
        $admin->refresh();

        $legacy = URL::temporarySignedRoute('email-change.confirm', now()->addHour(), [
            'user' => $admin->id,
            'hash' => sha1(mb_strtolower($admin->pending_email)),
        ]);

        $this->get($legacy)->assertOk()->assertSee(__('email_change.confirm_button'));
    }
}
