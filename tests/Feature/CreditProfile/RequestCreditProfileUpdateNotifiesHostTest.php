<?php

namespace Tests\Feature\CreditProfile;

use App\Mail\Borrower\UpdateCreditProfileRequestMail;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\CreditProfileRequest;
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
 * The borrower asks for their credit profile to be updated
 * (POST v1/borrower/profile/credit-profile/update-request/{id}) after the host
 * has saved their iSoftPull report. The host's bell must open that borrower's
 * request, as the first request's notification does, not the request list.
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
        ]);

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

    private function makeRequest(): CreditProfileRequest
    {
        return CreditProfileRequest::create([
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
        ]);
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
    public function the_host_is_still_mailed_a_link_to_the_request(): void
    {
        $request = $this->makeRequest();

        $this->requestUpdate()->assertOk();

        Mail::assertQueued(UpdateCreditProfileRequestMail::class, fn ($mail) => $mail->hasTo('host@example.test')
            && $mail->creditRequest->is($request));
    }

    #[Test]
    public function without_a_request_on_file_the_bell_still_rings_and_opens_the_list(): void
    {
        // The host saved the report without the borrower ever submitting a
        // request: there is no request page to open, and no request mail to build.
        $this->requestUpdate()->assertOk();

        $this->assertSame('/intake-review/credit-profile-requests', rtrim((string) $this->hostBellUrl(), '/'));
        Mail::assertNotQueued(UpdateCreditProfileRequestMail::class);
    }
}
