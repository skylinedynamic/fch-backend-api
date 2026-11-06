<?php

namespace Tests\Feature\EmailChange;

use App\Mail\Account\EmailChangeConfirmationMail;
use App\Mail\Account\EmailChangeRequestedMail;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-204: on the partner portal's Profile the email was read-only and Save
 * said "Profile updated successfully." while ignoring the email. A partner or
 * staff user now changes their own sign-in email from Profile through the same
 * process as every other change: the new address waits as pending until it is
 * confirmed (with the password) from the link sent there, and the old address
 * is told and can cancel. Saving the current email again withdraws a pending
 * change.
 */
class PartnerProfileEmailChangeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PROFILE = '/api/v1/common/auth-profile/update-profile';

    private Partner $partner;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner(['email' => 'owner@example.test']);
        $this->owner = $this->makePartnerAdmin($this->partner, [], ['email' => 'owner@example.test']);
    }

    private function save(User $user, string $email)
    {
        $this->actingAsUser($user);

        return $this->postJson(self::PROFILE, [
            'first_name' => 'Rita',
            'last_name' => 'Owner',
            'phone_number' => null,
            'email' => $email,
        ]);
    }

    private function mailsTo(string $class, string $email): int
    {
        return Mail::sent($class)->merge(Mail::queued($class))->filter(fn ($mail) => $mail->hasTo($email))->count();
    }

    #[Test]
    public function a_new_email_waits_for_confirmation_and_both_addresses_are_told(): void
    {
        $this->save($this->owner, 'new.owner@example.test')
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@example.test')
            ->assertJsonPath('data.pending_email', 'new.owner@example.test')
            ->assertJsonPath('message', __('email_change.requested', ['email' => 'new.owner@example.test']));

        $owner = $this->owner->fresh();
        $this->assertSame('owner@example.test', $owner->email, 'not changed until confirmed');
        $this->assertSame('new.owner@example.test', $owner->pending_email);
        $this->assertSame('owner@example.test', $this->partner->fresh()->email);
        $this->assertSame(1, $this->mailsTo(EmailChangeConfirmationMail::class, 'new.owner@example.test'));
        $this->assertSame(1, $this->mailsTo(EmailChangeRequestedMail::class, 'owner@example.test'));
    }

    #[Test]
    public function the_signed_in_user_carries_the_pending_email_so_profile_can_show_it_after_a_reload(): void
    {
        $this->save($this->owner, 'new.owner@example.test')->assertOk();

        $this->getJson('/api/v1/common/refresh-user')
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@example.test')
            ->assertJsonPath('data.pending_email', 'new.owner@example.test');
    }

    // A partner whose change the host cancels kept seeing it on Profile: their
    // browser holds the signed-in user, pending_email included. is_refresh
    // makes the panel reload that user on its next API call.
    #[Test]
    public function the_host_cancelling_the_change_makes_the_partners_session_reload_their_user(): void
    {
        $this->save($this->owner, 'new.owner@example.test')->assertOk();
        $this->owner->forceFill(['is_refresh' => false])->save();

        $this->actingAsUser($this->makePartnerAdmin(\App\Models\Partner\Partner::host()));
        $this->postJson('/api/v1/common/email-change/cancel', ['user_id' => $this->owner->id])->assertOk();

        $owner = $this->owner->fresh();
        $this->assertNull($owner->pending_email);
        $this->assertTrue((bool) $owner->is_refresh);

        $this->actingAsUser($owner);
        $this->getJson('/api/v1/common/refresh-user')->assertOk()->assertJsonPath('data.pending_email', null);
        $this->assertFalse((bool) $owner->fresh()->is_refresh, 'reloaded once, then clear');
    }

    #[Test]
    public function a_change_started_for_the_user_by_someone_else_makes_their_session_reload_too(): void
    {
        $this->owner->forceFill(['is_refresh' => false])->save();

        app(\App\Services\Global\Account\EmailChangeService::class)->request($this->owner, 'set.by.host@example.test');

        $this->assertTrue((bool) $this->owner->fresh()->is_refresh);
    }

    #[Test]
    public function a_staff_user_changes_their_own_email_the_same_way(): void
    {
        $staff = $this->makeStaffUser($this->partner, [], ['email' => 'staff@example.test']);

        $this->save($staff, 'staff.new@example.test')
            ->assertOk()
            ->assertJsonPath('data.pending_email', 'staff.new@example.test');

        $this->assertSame('staff@example.test', $staff->fresh()->email);
        $this->assertSame(1, $this->mailsTo(EmailChangeConfirmationMail::class, 'staff.new@example.test'));
    }

    #[Test]
    public function saving_the_same_email_starts_nothing(): void
    {
        $this->save($this->owner, 'owner@example.test')
            ->assertOk()
            ->assertJsonPath('message', __('profile.profile_update_success'));

        $this->assertNull($this->owner->fresh()->pending_email);
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function saving_the_current_email_again_withdraws_a_pending_change(): void
    {
        $this->save($this->owner, 'new.owner@example.test')->assertOk();

        $this->save($this->owner, 'owner@example.test')
            ->assertOk()
            ->assertJsonPath('data.pending_email', null)
            ->assertJsonPath('message', __('email_change.cancelled'));

        $this->assertNull($this->owner->fresh()->pending_email);
    }

    #[Test]
    public function an_email_another_account_uses_is_refused_and_nothing_changes(): void
    {
        $this->makeStaffUser($this->partner, [], ['email' => 'taken@example.test']);

        $this->save($this->owner, 'taken@example.test')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertNull($this->owner->fresh()->pending_email);
        $this->assertNotSame('Rita Owner', $this->owner->fresh()->name, 'a refused save writes nothing');
        Mail::assertNothingOutgoing();
    }
}
