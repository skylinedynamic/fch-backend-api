<?php

namespace Tests\Feature\EmailChange;

use App\Http\Controllers\Api\V1\Borrower\Profile\EmailChangeController;
use App\Http\Controllers\Api\V1\Borrower\Profile\PersonalInfoController;
use App\Mail\Account\EmailChangeConfirmationMail;
use App\Mail\Account\EmailChangeRequestedMail;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Borrower\Profile\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A borrower changing their own email from the borrower portal's profile goes
 * through the same process as every other email change: the new address waits
 * as pending until it's confirmed (with the password) from the link sent
 * there, and the old address is told and can cancel. The profile save used to
 * switch the email at once, reset the password to a random one and mail it to
 * the new address - so a typo locked the borrower out, and whoever owned the
 * typed address got a working password.
 */
#[CoversClass(PersonalInfoController::class)]
#[CoversClass(EmailChangeController::class)]
#[CoversClass(ProfileService::class)]
class BorrowerProfileEmailChangeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PROFILE = '/api/v1/borrower/profile/personal-info';
    private const RESEND = '/api/v1/borrower/profile/email-change/resend';
    private const CANCEL = '/api/v1/borrower/profile/email-change/cancel';

    private Partner $partner;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->partner = $this->makeHostPartner();
        $this->user = $this->makeBorrowerUser($this->partner, ['email' => 'craig@example.test']);
        Passport::actingAs($this->user);
    }

    /** A complete, valid profile payload. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Craig',
            'middle_name' => null,
            'last_name' => 'Rice',
            'email' => 'craig@example.test',
            'phone_number' => '5552345678',
            'country' => 'United States',
            'country_code' => '+1',
            'state' => 'Texas',
            'city' => 'Austin',
            'zip_code' => '78701',
            'home_address' => '100 Congress Ave',
            'ssn' => '123-45-6789',
            'date_of_birth' => '1985-04-12',
        ], $overrides);
    }

    private function requestChange(string $email = 'new@example.test'): void
    {
        $this->postJson(self::PROFILE, $this->payload(['email' => $email]))->assertOk();
    }

    // ---- Saving a new email ---------------------------------------------------

    #[Test]
    public function a_new_email_waits_for_confirmation_instead_of_changing_at_once(): void
    {
        $this->postJson(self::PROFILE, $this->payload(['email' => 'new@example.test']))
            ->assertOk()
            ->assertJsonPath('data.email', 'craig@example.test')
            ->assertJsonPath('data.pending_email', 'new@example.test')
            ->assertJsonPath('message', __('email_change.requested', ['email' => 'new@example.test']));

        $user = $this->user->fresh();
        $this->assertSame('craig@example.test', $user->email);
        $this->assertSame('new@example.test', $user->pending_email);
        $this->assertSame('craig@example.test', $user->entity->email);
    }

    #[Test]
    public function the_new_address_gets_the_confirmation_link_and_the_old_one_is_told(): void
    {
        $this->requestChange();

        Mail::assertSent(EmailChangeConfirmationMail::class, fn ($mail) => $mail->hasTo('new@example.test'));
        Mail::assertSent(EmailChangeRequestedMail::class, fn ($mail) => $mail->hasTo('craig@example.test'));
    }

    #[Test]
    public function the_password_is_kept_and_no_new_one_is_mailed(): void
    {
        $this->requestChange();

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
        Mail::assertNotSent(BorrowerAccountCreatedMail::class);
    }

    #[Test]
    public function the_rest_of_the_profile_is_still_saved(): void
    {
        $this->postJson(self::PROFILE, $this->payload(['email' => 'new@example.test', 'first_name' => 'Craig2', 'city' => 'Dallas']))
            ->assertOk();

        $user = $this->user->fresh();
        $this->assertSame('Craig2', $user->entity->first_name);
        $this->assertSame('Dallas', $user->entity->city);
        $this->assertSame('Craig2 Rice', $user->name);
    }

    #[Test]
    public function the_profile_shows_the_pending_email(): void
    {
        $this->requestChange();

        $this->getJson(self::PROFILE)
            ->assertOk()
            ->assertJsonPath('data.email', 'craig@example.test')
            ->assertJsonPath('data.pending_email', 'new@example.test');
    }

    #[Test]
    public function saving_the_shown_email_again_is_not_a_change(): void
    {
        $this->requestChange();
        Mail::fake();

        // Same address, different case, and an unrelated edit: the pending change stays.
        $this->postJson(self::PROFILE, $this->payload(['email' => 'CRAIG@example.test', 'city' => 'Dallas']))
            ->assertOk()
            ->assertJsonPath('message', __('borrower.update_personal_profile'));

        $this->assertSame('new@example.test', $this->user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    // ---- Unique ---------------------------------------------------------------

    #[Test]
    public function an_email_used_by_another_login_is_refused_and_nothing_is_saved(): void
    {
        $this->makeBorrowerUser($this->partner, ['email' => 'taken@example.test']);

        $this->postJson(self::PROFILE, $this->payload(['email' => 'Taken@Example.test', 'city' => 'Dallas']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $user = $this->user->fresh();
        $this->assertNull($user->pending_email);
        $this->assertNotSame('Dallas', $user->entity->city);
        Mail::assertNothingSent();
    }

    #[Test]
    public function an_email_held_only_by_a_deleted_login_can_be_requested(): void
    {
        $this->makeBorrowerUser($this->partner, ['email' => 'gone@example.test'])->delete();

        $this->requestChange('gone@example.test');

        $this->assertSame('gone@example.test', $this->user->fresh()->pending_email);
    }

    // ---- Resend and cancel ----------------------------------------------------

    #[Test]
    public function the_borrower_can_resend_the_confirmation_link(): void
    {
        $this->requestChange();
        Mail::fake();

        $this->postJson(self::RESEND)
            ->assertOk()
            ->assertJsonPath('data.pending_email', 'new@example.test')
            ->assertJsonPath('message', __('email_change.resent', ['email' => 'new@example.test']));

        Mail::assertSent(EmailChangeConfirmationMail::class, fn ($mail) => $mail->hasTo('new@example.test'));
    }

    #[Test]
    public function resending_with_nothing_pending_says_so(): void
    {
        $this->postJson(self::RESEND)
            ->assertStatus(422)
            ->assertJsonPath('message', __('email_change.nothing_pending'));
    }

    #[Test]
    public function the_borrower_can_cancel_the_pending_change(): void
    {
        $this->requestChange();

        $this->postJson(self::CANCEL)
            ->assertOk()
            ->assertJsonPath('data.pending_email', null)
            ->assertJsonPath('message', __('email_change.cancelled'));

        $this->assertNull($this->user->fresh()->pending_email);
        $this->assertSame('craig@example.test', $this->user->fresh()->email);
    }

    #[Test]
    public function resend_and_cancel_only_ever_act_on_the_borrowers_own_login(): void
    {
        $other = $this->makeBorrowerUser($this->partner);
        $other->forceFill(['pending_email' => 'other-new@example.test', 'pending_email_requested_at' => now()])->save();

        // A user_id in the body is ignored.
        $this->postJson(self::CANCEL, ['user_id' => $other->id])->assertOk();
        $this->postJson(self::RESEND, ['user_id' => $other->id])->assertStatus(422);

        $this->assertSame('other-new@example.test', $other->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_partner_login_cannot_use_the_borrower_endpoints(): void
    {
        Passport::actingAs($this->makePartnerAdmin($this->partner));

        $this->postJson(self::RESEND)->assertForbidden();
        $this->postJson(self::CANCEL)->assertForbidden();
    }
}
