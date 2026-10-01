<?php

namespace Tests\Feature\EmailChange;

use App\Mail\Account\EmailChangeConfirmationMail;
use App\Mail\Account\EmailChangedMail;
use App\Mail\Account\EmailChangeRequestedMail;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A mistyped new email sends the confirmation link to a stranger's inbox.
 * These are what stop that stranger from taking the account over:
 *
 *  - the link alone isn't enough — confirming asks for the account password;
 *  - the old address can cancel the change before it's confirmed;
 *  - and, once confirmed, the old address can undo it for a week.
 */
#[CoversClass(EmailChangeService::class)]
class EmailChangeSafeguardsTest extends TestCase
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

    private function makeUser(string $email): User
    {
        return User::create([
            'name' => 'Pat Doe',
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $this->partner->id,
        ]);
    }

    private function confirmationUrl(): string
    {
        $url = null;
        Mail::assertSent(EmailChangeConfirmationMail::class, function ($mail) use (&$url) {
            $url = $mail->confirmationUrl;
            return true;
        });

        return $url;
    }

    private function cancelUrl(): string
    {
        $url = null;
        Mail::assertSent(EmailChangeRequestedMail::class, function ($mail) use (&$url) {
            $url = $mail->cancelUrl;
            return true;
        });

        return $url;
    }

    private function revertUrl(): string
    {
        $url = null;
        Mail::assertSent(EmailChangedMail::class, function ($mail) use (&$url) {
            $url = $mail->revertUrl;
            return true;
        });

        return $url;
    }

    /** Request new@ and confirm it with the right password. */
    private function changeEmail(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $this->post($this->confirmationUrl(), ['password' => 'secret-password'])->assertOk();
        $this->assertSame('new@example.test', $this->user->refresh()->email);
    }

    // ── Confirming needs the password ─────────────────────────────────────

    #[Test]
    public function opening_the_confirmation_link_only_asks_for_the_password(): void
    {
        // Mail scanners and link previews open links too — opening one must
        // never be what changes the email.
        $this->service->request($this->user, 'new@example.test');

        $this->get($this->confirmationUrl())
            ->assertOk()
            ->assertSee('name="password"', false);

        $this->assertSame('old@example.test', $this->user->refresh()->email);
    }

    #[Test]
    public function the_wrong_password_does_not_change_the_email(): void
    {
        $this->service->request($this->user, 'new@example.test');

        $this->post($this->confirmationUrl(), ['password' => 'a-guess'])
            ->assertOk()
            ->assertSee(__('email_change.wrong_password'));

        $this->user->refresh();
        $this->assertSame('old@example.test', $this->user->email);
        $this->assertSame('new@example.test', $this->user->pending_email);
    }

    #[Test]
    public function too_many_wrong_passwords_lock_the_confirmation(): void
    {
        $this->service->request($this->user, 'new@example.test');
        $url = $this->confirmationUrl();

        for ($i = 0; $i < EmailChangeService::MAX_PASSWORD_ATTEMPTS; $i++) {
            $this->post($url, ['password' => "guess-$i"]);
        }

        // Even the right password is refused now.
        $this->post($url, ['password' => 'secret-password'])
            ->assertOk()
            ->assertSee(__('email_change.too_many_attempts_title'));

        $this->assertSame('old@example.test', $this->user->refresh()->email);
    }

    // ── The old address can cancel ────────────────────────────────────────

    #[Test]
    public function the_notice_to_the_old_address_carries_a_cancel_link(): void
    {
        $this->service->request($this->user, 'new@example.test');

        Mail::assertSent(EmailChangeRequestedMail::class, fn ($mail) => $mail->hasTo('old@example.test')
            && str_contains($mail->render(), e($mail->cancelUrl)));
    }

    #[Test]
    public function opening_the_cancel_link_changes_nothing_until_it_is_submitted(): void
    {
        $this->service->request($this->user, 'new@example.test');

        $this->get($this->cancelUrl())->assertOk()->assertSee(__('email_change.cancel_button'));

        $this->assertSame('new@example.test', $this->user->refresh()->pending_email);
    }

    #[Test]
    public function cancelling_from_the_old_address_voids_the_confirmation_link(): void
    {
        $this->service->request($this->user, 'new@example.test');

        $this->post($this->cancelUrl())->assertOk()->assertSee(__('email_change.cancelled_title'));
        $this->post($this->confirmationUrl(), ['password' => 'secret-password']);

        $this->user->refresh();
        $this->assertNull($this->user->pending_email);
        $this->assertSame('old@example.test', $this->user->email);
    }

    #[Test]
    public function a_cancel_link_for_an_older_request_leaves_the_newer_one_alone(): void
    {
        $this->service->request($this->user, 'first@example.test');
        $staleCancel = $this->cancelUrl();

        $this->service->request($this->user->refresh(), 'second@example.test');
        $this->post($staleCancel)->assertOk()->assertSee(__('email_change.invalid_title'));

        $this->assertSame('second@example.test', $this->user->refresh()->pending_email);
    }

    // ── The old address can undo a confirmed change ───────────────────────

    #[Test]
    public function confirming_tells_the_old_address_and_gives_it_an_undo_link(): void
    {
        $this->changeEmail();

        Mail::assertSent(EmailChangedMail::class, fn ($mail) => $mail->hasTo('old@example.test')
            && str_contains($mail->render(), e($mail->revertUrl)));
    }

    #[Test]
    public function opening_the_undo_link_changes_nothing_until_it_is_submitted(): void
    {
        $this->changeEmail();

        $this->get($this->revertUrl())->assertOk()->assertSee(__('email_change.revert_button'));

        $this->assertSame('new@example.test', $this->user->refresh()->email);
    }

    #[Test]
    public function undoing_puts_the_old_email_back_on_the_login_and_its_record(): void
    {
        $employee = Employee::create(['partner_id' => $this->partner->id, 'first_name' => 'Pat Doe', 'email' => 'old@example.test']);
        $this->user->update(['entity_type' => Employee::class, 'entity_id' => $employee->id, 'user_type' => User::EMPLOYEE]);

        $this->changeEmail();
        $this->post($this->revertUrl())->assertOk()->assertSee(__('email_change.reverted_title'));

        $this->assertSame('old@example.test', $this->user->refresh()->email);
        $this->assertSame('old@example.test', $employee->refresh()->email);
    }

    #[Test]
    public function undoing_locks_out_whoever_took_the_account(): void
    {
        // They may have reset the password from the new inbox, and may still be
        // signed in — so the password is replaced, their reset links dropped,
        // and every session ended. The owner sets a new password from the old
        // address with "Forgot password".
        $this->changeEmail();

        DB::table('oauth_access_tokens')->insert([
            'id' => 'intruder-token', 'user_id' => $this->user->id, 'client_id' => 1,
            'revoked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'new@example.test', 'token' => Hash::make('reset'), 'created_at' => now(),
        ]);

        $this->post($this->revertUrl());

        $this->assertFalse(Hash::check('secret-password', $this->user->refresh()->password));
        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', 'intruder-token')->value('revoked'));
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', 'new@example.test')->exists());
    }

    #[Test]
    public function the_undo_link_lasts_a_week(): void
    {
        $this->changeEmail();
        $url = $this->revertUrl();

        $this->travel(EmailChangeService::REVERT_LIFETIME_DAYS)->days();
        $this->travel(1)->hours();
        $this->post($url)->assertOk()->assertSee(__('email_change.invalid_title'));

        $this->assertSame('new@example.test', $this->user->refresh()->email);
    }

    #[Test]
    public function an_undo_link_is_used_up_once_it_has_been_followed(): void
    {
        $this->changeEmail();
        $url = $this->revertUrl();

        $this->post($url);
        $this->post($url)->assertOk()->assertSee(__('email_change.invalid_title'));

        $this->assertSame('old@example.test', $this->user->refresh()->email);
    }

    #[Test]
    public function undoing_is_refused_if_another_account_has_since_taken_the_old_email(): void
    {
        $this->changeEmail();
        $this->makeUser('old@example.test');

        $this->post($this->revertUrl())->assertOk()->assertSee(__('email_change.taken_title'));

        $this->assertSame('new@example.test', $this->user->refresh()->email);
    }
}
