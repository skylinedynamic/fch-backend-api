<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Mail\Borrower\CreditProfileRequestMail;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /** The SSN on the borrower's stored request, as the app reads it. */
    private function storedSsn(): ?string
    {
        return CreditProfileRequest::where('borrower_id', $this->borrower->id)->firstOrFail()->ssn;
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
    public function a_stored_request_is_not_logged(): void
    {
        // "Starting ... / Successfully stored ..." on every submission: noise.
        // A failure is still logged.
        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->level . ': ' . $message->message;
        });

        $this->submit()->assertOk();

        $this->assertSame([], array_values(array_filter($logged, fn ($line) => stripos($line, 'CreditProfileRequest') !== false)));
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

        $this->assertSame('123456789', $this->storedSsn());
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

    /**
     * "Request Update" on a pulled credit profile told the host in the bell but
     * linked to the list, so clicking it never opened the borrower's request.
     */
    #[Test]
    public function an_update_request_links_the_host_to_the_borrowers_request(): void
    {
        Notification::fake();
        $hostLogin = $this->hostLogin();
        $id = $this->submit()->assertOk()->json('data.id');
        $profile = \App\Models\Borrower\BorrowerCreditProfile::create(['borrower_id' => $this->borrower->id, 'fico_score' => 700]);

        $this->postJson("/api/v1/borrower/profile/credit-profile/update-request/{$profile->id}", [
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
        ])->assertOk();

        Notification::assertSentTo($hostLogin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'host.credit_profile.update_requested'
            && $n->toArray($hostLogin)['url'] === "/intake-review/credit-profile-requests/{$id}");
    }

    #[Test]
    public function an_update_request_without_a_request_on_file_links_to_the_list(): void
    {
        // A profile the host pulled without the borrower ever asking: there is
        // no request to open, so the list is the closest page.
        Notification::fake();
        $hostLogin = $this->hostLogin();
        $profile = \App\Models\Borrower\BorrowerCreditProfile::create(['borrower_id' => $this->borrower->id, 'fico_score' => 700]);

        $this->postJson("/api/v1/borrower/profile/credit-profile/update-request/{$profile->id}", [
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
        ])->assertOk();

        Notification::assertSentTo($hostLogin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'host.credit_profile.update_requested'
            && $n->toArray($hostLogin)['url'] === '/intake-review/credit-profile-requests');
    }

    private function hostLogin(): User
    {
        return User::create([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'user_type' => User::PARTNER,
            'partner_id' => $this->borrower->partner_id,
            'entity_type' => Partner::class,
            'entity_id' => $this->borrower->partner_id,
        ]);
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

    // -- the SSN on the borrower's profile as the default --------------------

    /**
     * The profile already holds the borrower's full SSN, and the form shows it
     * masked as the default - the browser never has the number to send back. An
     * SSN left out of the request is the one on the profile.
     */
    #[Test]
    public function without_an_ssn_the_one_on_the_profile_is_used(): void
    {
        $this->borrower->update(['ssn' => '234-56-7890']);

        $this->submit(['ssn' => null])->assertOk();

        $this->assertSame('234567890', $this->storedSsn());
    }

    #[Test]
    public function a_typed_ssn_wins_over_the_one_on_the_profile(): void
    {
        $this->borrower->update(['ssn' => '234-56-7890']);

        $this->submit(['ssn' => '123-45-6789'])->assertOk();

        $this->assertSame('123456789', $this->storedSsn());
    }

    #[Test]
    public function the_ssn_is_required_without_a_full_one_on_the_profile(): void
    {
        $this->submit(['ssn' => null])->assertStatus(422)->assertJsonValidationErrors('ssn');

        // Only the last four on file is not enough to pull credit on.
        $this->borrower->update(['last_4_ssn' => '7890']);
        $this->submit(['ssn' => null])->assertStatus(422)->assertJsonValidationErrors('ssn');

        $this->assertDatabaseMissing('credit_profile_requests', ['borrower_id' => $this->borrower->id]);
    }

    /**
     * Defect (D10): the SSN a credit pull is keyed on was stored in plain text,
     * and GLBA's Safeguards Rule expects it encrypted at rest. The app still
     * reads the nine digits; the column only ever holds ciphertext.
     */
    #[Test]
    public function the_ssn_is_encrypted_at_rest(): void
    {
        $this->submit(['ssn' => '123-45-6789'])->assertOk();

        $raw = \Illuminate\Support\Facades\DB::table('credit_profile_requests')->where('borrower_id', $this->borrower->id)->value('ssn');
        $this->assertStringNotContainsString('123456789', (string) $raw);
        $this->assertSame('123456789', \Illuminate\Support\Facades\Crypt::decryptString($raw));
        $this->assertSame('123456789', $this->storedSsn());
    }

    #[Test]
    public function requests_stored_before_are_encrypted_by_the_migration(): void
    {
        $id = \Illuminate\Support\Facades\DB::table('credit_profile_requests')->insertGetId([
            'borrower_id' => $this->borrower->id, 'first_name' => 'Odessa', 'last_name' => 'Mccall',
            'address' => '1 Main St', 'city' => 'Austin', 'state' => 'Texas', 'zip' => '78701',
            'ssn' => '123456789', 'date_of_birth' => '1986-09-01', 'phone' => '5125550100',
            'email' => 'odessa@example.test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_12_000000_encrypt_credit_profile_requests_ssn.php');

        $migration->up();
        $migration->up(); // run twice: an already-encrypted value is left alone

        $raw = \Illuminate\Support\Facades\DB::table('credit_profile_requests')->where('id', $id)->value('ssn');
        $this->assertSame('123456789', \Illuminate\Support\Facades\Crypt::decryptString($raw));
    }

    #[Test]
    public function resubmitting_returns_the_same_request(): void
    {
        $first = $this->submit()->assertOk()->json('data.id');
        $second = $this->submit(['city' => 'Austin'])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_soft_deleted_request_is_brought_back_rather_than_duplicated(): void
    {
        // The lookup used to skip soft-deleted rows, so the next submission
        // added a second row beside the deleted one.
        $id = $this->submit()->assertOk()->json('data.id');
        CreditProfileRequest::findOrFail($id)->delete();

        $this->submit(['city' => 'Austin'])->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, CreditProfileRequest::withTrashed()->where('borrower_id', $this->borrower->id)->count());
        $restored = CreditProfileRequest::findOrFail($id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('Austin', $restored->city);
    }

    #[Test]
    public function an_unchanged_resubmission_still_counts_as_the_latest_request(): void
    {
        // The list shows the latest request first by updated_at, so a borrower
        // who submits the very same details again must still come to the top.
        Carbon::setTestNow('2026-10-01 09:00:00');
        $id = $this->submit()->assertOk()->json('data.id');

        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->submit()->assertOk();
        Carbon::setTestNow();

        $this->assertSame('2026-10-15 09:00:00', CreditProfileRequest::findOrFail($id)->updated_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_database_holds_one_request_per_borrower(): void
    {
        // Enforced by a unique index, not only by the lookup: two submissions
        // arriving together can no longer both insert.
        $this->submit()->assertOk();

        $this->expectException(UniqueConstraintViolationException::class);

        CreditProfileRequest::create($this->rowFor($this->borrower->id));
    }

    #[Test]
    public function a_resubmission_notifies_the_host_again(): void
    {
        $this->submit()->assertOk();
        $this->submit(['city' => 'Austin'])->assertOk();

        Mail::assertQueued(CreditProfileRequestMail::class, 2);
    }

    #[Test]
    public function the_hosts_bell_notification_is_also_pushed_in_realtime(): void
    {
        // "Push" is the broadcast channel (Reverb): the bell updates without a
        // reload. The type's channels decide it, so this pins them.
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

        $this->submit()->assertOk();

        Notification::assertSentTo($hostLogin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'host.credit_profile.requested'
            && in_array('database', $n->via($hostLogin), true)
            && in_array('broadcast', $n->via($hostLogin), true));
    }

    /** A complete row for the table, as the service would write it. */
    private function rowFor(int $borrowerId): array
    {
        return ['borrower_id' => $borrowerId] + collect($this->payload())
            ->except(['credit_authorization_accepted', 'e_consent_accepted'])
            ->all();
    }
}
