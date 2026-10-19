<?php

namespace Tests\Feature\CreditProfile;

use App\Mail\Borrower\UpdateCreditProfileRequestMail;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The borrower asks for their credit profile to be updated
 * (POST v1/borrower/profile/credit-profile/update-request/{id}) after the host
 * has saved their iSoftPull report.
 *
 * The update is a credit profile request like the first one: it shows at the
 * top of Intake & Review -> Request Credit Profile, and the host's bell and mail
 * open it. A borrower who never submitted the request form (the host saved the
 * report directly) gets one, built from their profile - with whatever it holds,
 * empty where it holds nothing.
 */
class RequestCreditProfileUpdateNotifiesHostTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;
    private User $hostLogin;
    private BorrowerCreditProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->hostLogin = User::create([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'user_type' => User::PARTNER,
            'partner_id' => $host->id,
            'entity_type' => Partner::class,
            'entity_id' => $host->id,
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
            'home_address' => '101 Tech Way, Apt 4B',
            'city' => 'Birmingham',
            'state' => 'Alabama',
            'postal_code' => '35203',
            'date_of_birth' => '1986-09-01',
            'phone_number' => '(546) 456-5465',
        ]);
        $this->borrower->update(['ssn' => '123-45-6789']);

        // The report the host saved from iSoftPull.
        $this->profile = BorrowerCreditProfile::create(['borrower_id' => $this->borrower->id]);

        Passport::actingAs(User::create([
            'name' => 'Odessa Mccall',
            'email' => 'odessa@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'partner_id' => $host->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]));
    }

    private function makeRequest(array $overrides = []): CreditProfileRequest
    {
        return CreditProfileRequest::create(array_merge([
            'borrower_id' => $this->borrower->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'address' => '270 Sharulia Road',
            'city' => 'Austin',
            'state' => 'Texas',
            'zip' => '78701',
            'ssn' => '123456789',
            'date_of_birth' => '1986-09-01',
            'phone' => '(342) 342-3423',
            'email' => 'odessa@example.test',
        ], $overrides));
    }

    private function requestUpdate()
    {
        return $this->postJson("/api/v1/borrower/profile/credit-profile/update-request/{$this->profile->id}", [
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
        ]);
    }

    private function hostBellUrl(): ?string
    {
        $url = null;
        Notification::assertSentTo($this->hostLogin, DynamicNotification::class, function (DynamicNotification $n) use (&$url) {
            if ($n->typeKey !== 'host.credit_profile.update_requested') {
                return false;
            }
            $url = $n->toArray($this->hostLogin)['url'];

            return true;
        });

        return $url;
    }

    // -- A borrower with a request on file -------------------------------------

    #[Test]
    public function the_request_on_file_moves_to_the_top_and_is_not_duplicated(): void
    {
        $request = $this->makeRequest();
        $request->forceFill(['updated_at' => Carbon::now()->subDays(3)])->saveQuietly();

        $this->requestUpdate()->assertOk();

        $this->assertSame(1, CreditProfileRequest::withTrashed()->where('borrower_id', $this->borrower->id)->count());
        $this->assertTrue($request->fresh()->updated_at->greaterThan(Carbon::now()->subMinute()));
        // The details the borrower gave on the form stay as given.
        $this->assertSame('270 Sharulia Road', $request->fresh()->address);
    }

    #[Test]
    public function a_deleted_request_is_brought_back_rather_than_a_second_one_added(): void
    {
        $request = $this->makeRequest();
        $request->delete();

        $this->requestUpdate()->assertOk();

        $this->assertNull($request->fresh()->deleted_at);
        $this->assertSame(1, CreditProfileRequest::withTrashed()->where('borrower_id', $this->borrower->id)->count());
    }

    #[Test]
    public function the_hosts_bell_opens_the_borrowers_request(): void
    {
        $request = $this->makeRequest();

        $this->requestUpdate()->assertOk();

        $this->assertSame("/intake-review/credit-profile-requests/{$request->id}", $this->hostBellUrl());
        Notification::assertSentTo($this->hostLogin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'host.credit_profile.update_requested'
            && $n->toArray($this->hostLogin)['body'] === 'Odessa Mccall asked to update their credit profile.');
    }

    #[Test]
    public function the_host_is_mailed_a_link_to_the_request(): void
    {
        $request = $this->makeRequest();

        $this->requestUpdate()->assertOk();

        Mail::assertQueued(UpdateCreditProfileRequestMail::class, fn ($mail) => $mail->hasTo('host@example.test')
            && $mail->creditRequest->is($request));
    }

    // -- A borrower who never submitted the request form -----------------------

    #[Test]
    public function without_a_request_on_file_one_is_made_from_the_profile(): void
    {
        $this->requestUpdate()->assertOk();

        $request = CreditProfileRequest::where('borrower_id', $this->borrower->id)->sole();
        $this->assertSame('Odessa', $request->first_name);
        $this->assertSame('Mccall', $request->last_name);
        $this->assertSame('101 Tech Way, Apt 4B', $request->address);
        $this->assertSame('Birmingham', $request->city);
        $this->assertSame('Alabama', $request->state);
        $this->assertSame('123456789', $request->ssn);
        $this->assertSame('1986-09-01', Carbon::parse($request->date_of_birth)->toDateString());
        $this->assertSame('(546) 456-5465', $request->phone);
        $this->assertSame('odessa@example.test', $request->email);
        $this->assertSame('35203', $request->zip);
    }

    #[Test]
    public function a_detail_the_profile_does_not_hold_is_left_empty(): void
    {
        $this->borrower->update(['postal_code' => null, 'phone_number' => null]);

        $this->requestUpdate()->assertOk();

        $request = CreditProfileRequest::where('borrower_id', $this->borrower->id)->sole();
        // Not on the profile: left empty, not made up.
        $this->assertNull($request->zip);
        $this->assertNull($request->phone);
        $this->assertSame('101 Tech Way, Apt 4B', $request->address);
    }

    #[Test]
    public function only_the_last_four_of_the_ssn_on_file_leaves_the_requests_ssn_empty(): void
    {
        $this->borrower->forceFill(['ssn_encrypted' => null])->saveQuietly();
        $this->borrower->update(['last_4_ssn' => '6789']);
        $this->assertFalse($this->borrower->fresh()->hasFullSsn());

        $this->requestUpdate()->assertOk();

        $this->assertNull(CreditProfileRequest::where('borrower_id', $this->borrower->id)->sole()->ssn);
    }

    #[Test]
    public function without_a_request_on_file_the_bell_and_mail_open_the_new_one(): void
    {
        $this->requestUpdate()->assertOk();

        $request = CreditProfileRequest::where('borrower_id', $this->borrower->id)->sole();
        $this->assertSame("/intake-review/credit-profile-requests/{$request->id}", $this->hostBellUrl());
        Mail::assertQueued(UpdateCreditProfileRequestMail::class, fn ($mail) => $mail->creditRequest->is($request));
    }

    #[Test]
    public function the_consent_is_tied_to_the_request(): void
    {
        $this->requestUpdate()->assertOk();

        $request = CreditProfileRequest::where('borrower_id', $this->borrower->id)->sole();
        $consent = CreditAuthorizationConsent::where('borrower_id', $this->borrower->id)->latest('id')->firstOrFail();
        $this->assertSame($request->id, $consent->credit_profile_request_id);
    }

    #[Test]
    public function a_refused_update_request_makes_no_request(): void
    {
        $this->postJson("/api/v1/borrower/profile/credit-profile/update-request/{$this->profile->id}", [
            'credit_authorization_accepted' => false,
            'e_consent_accepted' => true,
        ])->assertUnprocessable();

        $this->assertSame(0, CreditProfileRequest::withTrashed()->count());
    }

    #[Test]
    public function the_host_lists_and_opens_a_request_with_empty_details(): void
    {
        $this->borrower->update(['postal_code' => null, 'phone_number' => null, 'date_of_birth' => null]);
        $this->requestUpdate()->assertOk();
        $request = CreditProfileRequest::where('borrower_id', $this->borrower->id)->sole();

        Passport::actingAs(User::create([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::ADMIN,
            'partner_id' => $this->borrower->partner_id,
        ]));

        $this->postJson('/api/v1/admin/credit-profile-requests', ['sort_field' => 'updated_at', 'sort_order' => 'desc'])
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $request->id);

        $this->getJson("/api/v1/admin/credit-profile-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.zip', null)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.date_of_birth', null)
            ->assertJsonPath('data.city', 'Birmingham');
    }
}
