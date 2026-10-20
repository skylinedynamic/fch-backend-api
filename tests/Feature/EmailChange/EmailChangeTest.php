<?php

namespace Tests\Feature\EmailChange;

use App\Mail\Account\EmailChangeConfirmationMail;
use App\Mail\Account\EmailChangeRequestedMail;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Changing the email someone logs in with.
 *
 * The login email is the key to the account, so a change is confirmed from the
 * new address before it takes effect. Until then the person keeps logging in
 * with the old one — a mistyped address can't lock them out, and a change
 * nobody asked for is announced to the address it would take over from.
 */
#[CoversClass(EmailChangeService::class)]
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private User $user;
    private EmailChangeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->user = $this->makeUser('old@example.test');
        $this->service = app(EmailChangeService::class);
    }

    private function makeUser(string $email, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Pat Doe',
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $this->partner->id,
        ], $attributes));
    }

    /** Submit the confirmation page: the account password is what completes it. */
    private function confirmWith(string $url, string $password = 'secret-password')
    {
        return $this->post($url, ['password' => $password]);
    }

    private function confirmationUrlSentTo(string $email): string
    {
        $url = null;
        Mail::assertSent(EmailChangeConfirmationMail::class, function ($mail) use ($email, &$url) {
            if (!$mail->hasTo($email)) {
                return false;
            }
            $url = $mail->confirmationUrl;
            return true;
        });

        return $url;
    }

    // ── Delivery ──────────────────────────────────────────────────────────

    #[Test]
    public function both_emails_are_sent_straight_away_not_queued(): void
    {
        $this->service->request($this->user, 'new@example.test');

        Mail::assertSent(EmailChangeRequestedMail::class, fn ($mail) => $mail->hasTo('old@example.test'));
        Mail::assertSent(EmailChangeConfirmationMail::class, fn ($mail) => $mail->hasTo('new@example.test'));
        Mail::assertNothingQueued();
    }

    #[Test]
    public function each_email_says_it_was_sent_to_the_address_it_went_to(): void
    {
        // Defect: the footer ("This email was sent to …") showed the old
        // address on the confirmation, which goes to the new one.
        $this->user->pending_email = 'new@example.test';

        $confirmation = (new EmailChangeConfirmationMail($this->user, 'https://example.test/confirm', 24))->render();
        $notice = (new EmailChangeRequestedMail($this->user, 'new@example.test', 'https://example.test/cancel'))->render();

        $this->assertStringContainsString('This email was sent to new@example.test', $confirmation);
        $this->assertStringContainsString('This email was sent to old@example.test', $notice);
    }

    #[Test]
    public function the_confirmation_link_lasts_24_hours(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $url = $this->confirmationUrlSentTo('new@example.test');

        $this->travel(23)->hours();
        $this->assertSame(24, EmailChangeService::LINK_LIFETIME_HOURS);
        $this->confirmWith($url)->assertOk();

        $this->assertSame('new@example.test', $this->user->refresh()->email);
    }

    // ── Requesting ────────────────────────────────────────────────────────

    #[Test]
    public function a_requested_email_is_held_as_pending_and_the_login_email_is_unchanged(): void
    {
        $this->service->request($this->user, 'new@example.test');

        $this->user->refresh();
        $this->assertSame('old@example.test', $this->user->email);
        $this->assertSame('new@example.test', $this->user->pending_email);
    }

    #[Test]
    public function requesting_the_current_email_cancels_a_pending_change(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $this->service->request($this->user->refresh(), 'OLD@example.test');

        $this->assertNull($this->user->refresh()->pending_email);
    }

    #[Test]
    public function an_email_already_used_by_another_login_is_refused(): void
    {
        $this->makeUser('taken@example.test');

        $this->expectException(ValidationException::class);
        $this->service->request($this->user, 'taken@example.test');
    }

    /**
     * A deleted login no longer holds its email: users.email has had no unique
     * index since 2026_03_06_114733, so the change goes through to the end.
     */
    #[Test]
    public function an_email_held_by_a_deleted_login_can_be_taken(): void
    {
        $gone = $this->makeUser('gone@example.test');
        $gone->delete();

        $this->service->request($this->user, 'gone@example.test');
        $this->confirmWith($this->confirmationUrlSentTo('gone@example.test'))->assertOk();

        $this->assertSame('gone@example.test', $this->user->refresh()->email);
        $this->assertSoftDeleted($gone);
    }

    // ── Confirming ────────────────────────────────────────────────────────

    #[Test]
    public function following_the_link_switches_the_login_email(): void
    {
        $this->service->request($this->user, 'new@example.test');

        $this->confirmWith($this->confirmationUrlSentTo('new@example.test'))->assertOk();

        $this->user->refresh();
        $this->assertSame('new@example.test', $this->user->email);
        $this->assertNull($this->user->pending_email);
        $this->assertNotNull($this->user->email_verified_at);
    }

    #[Test]
    public function the_password_is_not_changed_by_an_email_change(): void
    {
        // The old flow reset the password and emailed a new one in plain text.
        $this->service->request($this->user, 'new@example.test');
        $this->confirmWith($this->confirmationUrlSentTo('new@example.test'));

        $this->assertTrue(Hash::check('secret-password', $this->user->refresh()->password));
    }

    #[Test]
    public function confirming_signs_the_account_out_everywhere(): void
    {
        DB::table('oauth_access_tokens')->insert([
            'id' => 'token-1', 'user_id' => $this->user->id, 'client_id' => 1,
            'revoked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->request($this->user, 'new@example.test');
        $this->confirmWith($this->confirmationUrlSentTo('new@example.test'));

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', 'token-1')->value('revoked'));
    }

    #[Test]
    public function a_link_for_a_superseded_request_does_nothing(): void
    {
        $this->service->request($this->user, 'first@example.test');
        $staleUrl = $this->confirmationUrlSentTo('first@example.test');

        $this->service->request($this->user->refresh(), 'second@example.test');
        $this->confirmWith($staleUrl)->assertOk();

        $this->assertSame('old@example.test', $this->user->refresh()->email);
    }

    #[Test]
    public function a_tampered_link_does_nothing(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $url = $this->confirmationUrlSentTo('new@example.test');

        $this->confirmWith(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertOk();

        $this->assertSame('old@example.test', $this->user->refresh()->email);
    }

    #[Test]
    public function an_expired_link_does_nothing(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $url = $this->confirmationUrlSentTo('new@example.test');

        $this->travel(EmailChangeService::LINK_LIFETIME_HOURS + 1)->hours();
        $this->confirmWith($url)->assertOk();

        $this->assertSame('old@example.test', $this->user->refresh()->email);
    }

    #[Test]
    public function a_link_is_refused_if_the_email_was_taken_while_it_was_pending(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $url = $this->confirmationUrlSentTo('new@example.test');

        $this->makeUser('new@example.test');
        $this->confirmWith($url)->assertOk();

        $this->user->refresh();
        $this->assertSame('old@example.test', $this->user->email);
        $this->assertNull($this->user->pending_email);
    }

    // ── The record behind the login follows it ────────────────────────────

    #[Test]
    public function confirming_updates_the_linked_employee(): void
    {
        $employee = Employee::create(['partner_id' => $this->partner->id, 'first_name' => 'Pat Doe', 'email' => 'old@example.test']);
        $this->user->update(['entity_type' => Employee::class, 'entity_id' => $employee->id, 'user_type' => User::EMPLOYEE]);

        $this->service->request($this->user->refresh(), 'new@example.test');
        $this->confirmWith($this->confirmationUrlSentTo('new@example.test'));

        $this->assertSame('new@example.test', $employee->refresh()->email);
    }

    #[Test]
    public function confirming_updates_the_linked_client(): void
    {
        $borrower = Borrower::create([
            'partner_id' => $this->partner->id, 'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'old@example.test',
        ]);
        $this->user->update(['entity_type' => Borrower::class, 'entity_id' => $borrower->id, 'user_type' => User::BORROWER]);

        $this->service->request($this->user->refresh(), 'new@example.test');
        $this->confirmWith($this->confirmationUrlSentTo('new@example.test'));

        $this->assertSame('new@example.test', $borrower->refresh()->email);
    }

    #[Test]
    public function confirming_updates_the_linked_partner(): void
    {
        $this->user->update(['entity_type' => Partner::class, 'entity_id' => $this->partner->id, 'user_type' => User::PARTNER]);
        $this->partner->update(['email' => 'old@example.test']);

        $this->service->request($this->user->refresh(), 'new@example.test');
        $this->confirmWith($this->confirmationUrlSentTo('new@example.test'));

        $this->assertSame('new@example.test', $this->partner->refresh()->email);
    }

    // ── Resend / cancel ───────────────────────────────────────────────────

    #[Test]
    public function resending_sends_a_fresh_link_for_the_same_pending_email(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $this->service->resend($this->user->refresh());

        Mail::assertSent(EmailChangeConfirmationMail::class, 2);
    }

    #[Test]
    public function cancelling_clears_the_pending_email_and_voids_its_link(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $url = $this->confirmationUrlSentTo('new@example.test');

        $this->service->cancel($this->user->refresh());
        $this->confirmWith($url);

        $this->user->refresh();
        $this->assertNull($this->user->pending_email);
        $this->assertSame('old@example.test', $this->user->email);
    }
}
