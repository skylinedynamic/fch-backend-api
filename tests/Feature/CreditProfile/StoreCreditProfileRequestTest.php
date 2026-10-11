<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Mail\Borrower\CreditProfileRequestMail;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Submitting the credit profile request, through the route that owns it.
 *
 * The two FCRA consents are validated on this request - the API must refuse to
 * pull credit without both - but they are evidence, not profile fields. They
 * belong in `credit_authorization_consents`, written separately straight after.
 *
 * The controller handed the whole of validated() to the service, the model is
 * `$guarded = []`, and so Eloquent tried to insert the two consent keys as
 * columns of `credit_profile_requests`:
 *
 *     SQLSTATE[42S22]: Unknown column 'credit_authorization_accepted'
 *
 * Every submission failed in production. Nothing caught it because the consent
 * suite exercises the service directly and nothing posted to the route - which
 * is why this test does.
 */
class StoreCreditProfileRequestTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        $user = User::create([
            'name' => 'Odessa Mccall',
            'email' => 'odessa@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            // BorrowerMiddleware gates the whole route group on this.
            'user_type' => User::BORROWER,
            'partner_id' => $partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]);

        Passport::actingAs($user);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Odessa',
            'middle_name' => 'Jo',
            'last_name' => 'Mccall',
            'address' => '270 Sharulia Road',
            'apt_suite_other' => '5th floor',
            'city' => 'Dhaka',
            'state' => 'Alaska',
            'zip' => '12065',
            'ssn' => '123456789',
            'date_of_birth' => '1986-09-01',
            'phone' => '(342) 342-3423',
            'email' => 'odessa@example.test',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
        ], $overrides);
    }

    private function submit(array $overrides = [])
    {
        return $this->postJson(
            '/api/v1/borrower/profile/credit-profile-request',
            $this->payload($overrides)
        );
    }

    // -- the row, without the evidence fields on it -------------------------

    #[Test]
    public function a_complete_request_is_stored(): void
    {
        $this->submit()->assertOk();

        $this->assertDatabaseHas('credit_profile_requests', [
            'borrower_id' => $this->borrower->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
        ]);
    }

    #[Test]
    public function the_consents_are_not_written_onto_the_request_row(): void
    {
        // They have no columns here. Passing them through was the 500.
        $this->submit()->assertOk();

        $stored = CreditProfileRequest::where('borrower_id', $this->borrower->id)->first();

        $this->assertNotNull($stored);
        $this->assertArrayNotHasKey('credit_authorization_accepted', $stored->getAttributes());
        $this->assertArrayNotHasKey('e_consent_accepted', $stored->getAttributes());
    }

    #[Test]
    public function the_consent_is_recorded_against_the_request_it_authorised(): void
    {
        $this->submit()->assertOk();

        $stored = CreditProfileRequest::where('borrower_id', $this->borrower->id)->first();
        $consent = CreditAuthorizationConsent::where('borrower_id', $this->borrower->id)->first();

        $this->assertNotNull($consent, 'the FCRA evidence was not written');
        $this->assertSame($stored->id, $consent->credit_profile_request_id);
    }

    // -- the SSN this pull is keyed on --------------------------------------

    #[Test]
    public function the_ssn_is_stored_as_its_nine_digits(): void
    {
        $this->submit(['ssn' => '123-45-6789'])->assertOk();

        $this->assertDatabaseHas('credit_profile_requests', [
            'borrower_id' => $this->borrower->id,
            'ssn' => '123456789',
        ]);
    }

    #[Test]
    public function a_malformed_ssn_is_refused_before_anything_is_stored(): void
    {
        $this->submit(['ssn' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('ssn');

        $this->assertDatabaseCount('credit_profile_requests', 0);
    }

    #[Test]
    public function an_ssn_the_ssa_never_issues_is_refused(): void
    {
        $this->submit(['ssn' => '666-45-6789'])->assertStatus(422)->assertJsonValidationErrors('ssn');
    }

    #[Test]
    public function a_malformed_zip_is_refused(): void
    {
        $this->submit(['zip' => '787'])->assertStatus(422)->assertJsonValidationErrors('zip');
    }

    // -- no pull without both consents --------------------------------------

    #[Test]
    public function it_refuses_to_store_anything_without_the_credit_authorization(): void
    {
        $this->submit(['credit_authorization_accepted' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credit_authorization_accepted');

        $this->assertDatabaseCount('credit_profile_requests', 0);
        $this->assertDatabaseCount('credit_authorization_consents', 0);
    }

    #[Test]
    public function it_refuses_to_store_anything_without_the_e_sign_consent(): void
    {
        $this->submit(['e_consent_accepted' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('e_consent_accepted');

        $this->assertDatabaseCount('credit_profile_requests', 0);
    }

    // -- the host is told ----------------------------------------------------

    #[Test]
    public function the_host_partner_is_notified(): void
    {
        // Sent after the row is written, so while the insert was throwing this
        // never ran at all - the missing notification and the 500 were the same
        // bug. Pinned here so it cannot go quiet again.
        $this->submit()->assertOk();

        Mail::assertQueued(
            CreditProfileRequestMail::class,
            fn ($mail) => $mail->hasTo('host@example.test')
        );
    }

    #[Test]
    public function the_host_is_told_in_the_bell_with_a_link_to_the_request(): void
    {
        Notification::fake();
        $hostLogin = User::create([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'user_type' => User::PARTNER,
            'partner_id' => $this->borrower->partner_id,
            'entity_type' => Partner::class,
            'entity_id' => $this->borrower->partner_id,
        ]);

        $id = $this->submit()->assertOk()->json('data.id');

        Notification::assertSentTo($hostLogin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'host.credit_profile.requested'
            && $n->toArray($hostLogin)['body'] === 'Odessa Mccall submitted a credit profile request.'
            && $n->toArray($hostLogin)['url'] === "/intake-review/credit-profile-requests/{$id}");
    }

    #[Test]
    public function nothing_is_sent_when_the_request_was_refused(): void
    {
        $this->submit(['ssn' => 'abc'])->assertStatus(422);

        Mail::assertNothingQueued();
    }

    // -- resubmitting -------------------------------------------------------

    #[Test]
    public function submitting_again_updates_the_borrowers_one_request(): void
    {
        $this->submit()->assertOk();
        $this->submit(['city' => 'Austin'])->assertOk();

        $this->assertDatabaseCount('credit_profile_requests', 1);
        $this->assertDatabaseHas('credit_profile_requests', [
            'borrower_id' => $this->borrower->id,
            'city' => 'Austin',
        ]);
    }
}
