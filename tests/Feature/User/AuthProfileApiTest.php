<?php

namespace Tests\Feature\User;

use App\Http\Controllers\Api\V1\FCH\Common\AuthProfile\AuthProfileController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\EmailConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Common\Tier\TierController;
use App\Http\Controllers\Api\V1\FCH\Common\TierTask\TierTaskController;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\Setting\EmailConfiguration;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The signed-in partner-portal user's own profile (/common/auth-profile/*),
 * plus the small lookups and switches the portal shell reads: the tier and
 * tier-task lists and the email-configuration on/off switch.
 */
#[CoversClass(AuthProfileController::class)]
#[CoversClass(TierController::class)]
#[CoversClass(TierTaskController::class)]
#[CoversClass(EmailConfigurationController::class)]
class AuthProfileApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
    }

    #[Test]
    public function a_user_changes_their_password_with_the_current_one(): void
    {
        $user = $this->actingAsUser($this->makePartnerAdmin($this->partner, [], ['password' => Hash::make('Old-pass1!')]));

        $this->postJson('/api/v1/common/auth-profile/update-password', [
            'current_password' => 'Old-pass1!',
            'new_password' => 'New-pass2@',
            'new_password_confirmation' => 'New-pass2@',
        ])->assertOk();

        $this->assertTrue(Hash::check('New-pass2@', $user->fresh()->password));
    }

    /**
     * Defect (O24): a changed password left every other issued token alive, so
     * whoever held one - a stolen session included - stayed signed in.
     */
    #[Test]
    public function changing_the_password_signs_out_every_other_session_but_this_one(): void
    {
        $this->createPersonalAccessClient();
        $user = $this->makePartnerAdmin($this->partner, [], ['password' => Hash::make('Old-pass1!')]);
        $other = $user->createToken('other device')->token;
        $current = $user->createToken('this device');

        $this->withToken($current->accessToken)->postJson('/api/v1/common/auth-profile/update-password', [
            'current_password' => 'Old-pass1!',
            'new_password' => 'New-pass2@',
            'new_password_confirmation' => 'New-pass2@',
        ])->assertOk();

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $other->id)->value('revoked'));
        $this->assertFalse((bool) DB::table('oauth_access_tokens')->where('id', $current->token->id)->value('revoked'));
    }

    #[Test]
    public function a_borrower_changing_their_password_signs_out_their_other_sessions(): void
    {
        $this->createPersonalAccessClient();
        $user = $this->makeBorrowerUser($this->partner);
        $user->update(['password' => Hash::make('Old-pass1!')]);
        $other = $user->createToken('other device')->token;
        $current = $user->createToken('this device');

        $this->withToken($current->accessToken)->postJson('/api/v1/borrower/auth-profile/update-password', [
            'current_password' => 'Old-pass1!',
            'new_password' => 'New-pass2@',
            'new_password_confirmation' => 'New-pass2@',
        ])->assertOk();

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $other->id)->value('revoked'));
        $this->assertFalse((bool) DB::table('oauth_access_tokens')->where('id', $current->token->id)->value('revoked'));
    }

    #[Test]
    public function a_wrong_current_password_or_a_weak_new_one_is_refused(): void
    {
        $user = $this->actingAsUser($this->makePartnerAdmin($this->partner, [], ['password' => Hash::make('Old-pass1!')]));

        $this->postJson('/api/v1/common/auth-profile/update-password', [
            'current_password' => 'Wrong-pass1!',
            'new_password' => 'New-pass2@',
            'new_password_confirmation' => 'New-pass2@',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->postJson('/api/v1/common/auth-profile/update-password', [
            'current_password' => 'Old-pass1!',
            'new_password' => 'weak',
            'new_password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password');

        $this->assertTrue(Hash::check('Old-pass1!', $user->fresh()->password));
    }

    #[Test]
    public function a_partner_admins_name_is_kept_in_step_with_the_partner_record(): void
    {
        $user = $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/auth-profile/update-profile', [
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'phone_number' => '5550100',
            'email' => $user->email,
        ])->assertOk()->assertJsonPath('data.name', 'Pat Owner');

        $this->assertSame('Pat Owner', $this->partner->fresh()->full_name);
    }

    #[Test]
    public function a_staff_users_name_and_phone_follow_onto_their_employee_record(): void
    {
        $staff = $this->actingAsUser($this->makeStaffUser($this->partner));

        $this->postJson('/api/v1/common/auth-profile/update-profile', [
            'first_name' => 'Sam',
            'last_name' => 'Staff',
            'phone_number' => '5550199',
            'email' => $staff->email,
        ])->assertOk();

        $employee = Employee::find($staff->entity_id);
        $this->assertSame('Sam Staff', $employee->first_name);
        $this->assertSame('5550199', $employee->phone_number_1);
    }

    #[Test]
    public function the_profile_picture_is_filed_under_the_users_own_record(): void
    {
        $user = $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/auth-profile/update-profile-picture', ['image' => 'me.png'])
            ->assertOk();

        $this->assertSame("partners/{$this->partner->id}/me.png", $this->partner->fresh()->getRawOriginal('partner_image'));
        $this->assertSame("partners/{$this->partner->id}/me.png", $user->fresh()->getRawOriginal('image'));
    }

    #[Test]
    public function the_tier_and_task_lists_are_ordered(): void
    {
        $second = Tier::create(['name' => 'Tier 2', 'title' => 'Tier 2', 'order' => 2]);
        $first = Tier::create(['name' => 'Tier 1', 'title' => 'Tier 1', 'order' => 1]);
        TierTask::create(['tier_id' => $first->id, 'title' => 'Get an EIN', 'order' => 1]);
        TierTask::create(['tier_id' => $second->id, 'title' => 'Open a bank account', 'order' => 1]);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->assertSame([$first->id, $second->id], collect($this->postJson('/api/v1/common/tiers/fetch-all-tiers')->assertOk()->json('data'))->pluck('id')->all());
        $this->postJson('/api/v1/common/tier-tasks/fetch-all-tasks', ['tier_id' => $first->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Get an EIN');
    }

    #[Test]
    public function the_email_switch_flips_the_callers_own_configuration(): void
    {
        $other = $this->makeCertifiedPartner();
        EmailConfiguration::create(['partner_id' => $this->partner->id, 'key' => 'is_active', 'value' => '1']);
        EmailConfiguration::create(['partner_id' => $other->id, 'key' => 'is_active', 'value' => '1']);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson('/api/v1/common/email-configurations/toggle-status')->assertOk();

        $this->assertFalse((bool) EmailConfiguration::where('partner_id', $this->partner->id)->where('key', 'is_active')->value('value'));
        $this->assertTrue((bool) EmailConfiguration::where('partner_id', $other->id)->where('key', 'is_active')->value('value'));
    }

    #[Test]
    public function the_email_switch_needs_a_configuration_to_flip(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson('/api/v1/common/email-configurations/toggle-status')->assertNotFound();
    }
}
