<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\Borrower\Auth\AuthProfileController;
use App\Http\Controllers\Api\V1\Borrower\Profile\BorrowerCreditProfileController;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Two borrower-portal account actions: changing the password, and asking for
 * a fresh pull of the borrower's own credit profile (which records the
 * borrower's consent first).
 */
#[CoversClass(AuthProfileController::class)]
#[CoversClass(BorrowerCreditProfileController::class)]
class BorrowerAccountActionsApiTest extends TestCase
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
    public function a_borrower_changes_their_password_with_the_current_one(): void
    {
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner, [], ['password' => Hash::make('Old-pass1!')]));

        $this->postJson('/api/v1/borrower/auth-profile/update-password', [
            'current_password' => 'Wrong-pass1!',
            'new_password' => 'New-pass2@',
            'new_password_confirmation' => 'New-pass2@',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('Old-pass1!', $me->fresh()->password));

        $this->postJson('/api/v1/borrower/auth-profile/update-password', [
            'current_password' => 'Old-pass1!',
            'new_password' => 'New-pass2@',
            'new_password_confirmation' => 'New-pass2@',
        ])->assertOk();
        $this->assertTrue(Hash::check('New-pass2@', $me->fresh()->password));
    }

    #[Test]
    public function an_update_of_my_own_credit_profile_records_my_consent(): void
    {
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $profile = BorrowerCreditProfile::create(['borrower_id' => $me->entity_id, 'fico_score' => 700]);

        $this->postJson("/api/v1/borrower/profile/credit-profile/update-request/{$profile->id}", [
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
        ])->assertOk();

        $this->assertDatabaseHas('credit_authorization_consents', ['borrower_id' => $me->entity_id]);
    }

    #[Test]
    public function an_update_needs_both_consents(): void
    {
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $profile = BorrowerCreditProfile::create(['borrower_id' => $me->entity_id, 'fico_score' => 700]);

        $this->postJson("/api/v1/borrower/profile/credit-profile/update-request/{$profile->id}", ['credit_authorization_accepted' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('e_consent_accepted');
        $this->assertDatabaseCount('credit_authorization_consents', 0);
    }

    #[Test]
    public function another_borrowers_credit_profile_cannot_be_updated(): void
    {
        $theirs = BorrowerCreditProfile::create(['borrower_id' => $this->makeBorrower($this->partner)->id, 'fico_score' => 650]);
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->postJson("/api/v1/borrower/profile/credit-profile/update-request/{$theirs->id}", [
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
        ])->assertNotFound();
        $this->assertDatabaseCount('credit_authorization_consents', 0);
    }
}
