<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Request Credit Profile details screen.
 *
 * The date of birth is a calendar date: whatever day is stored is the day every
 * viewer sees, in the USA or in Bangladesh. The API sends it ready to display
 * so the portal prints it as-is rather than parsing it in the browser, which is
 * what used to hand a viewer behind UTC the previous day.
 */
class CreditProfileRequestDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Four Corner Host',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->actingAsAdminOf($this->host, 'admin@example.test');
    }

    private function actingAsAdminOf(Partner $partner, string $email): void
    {
        Passport::actingAs(User::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::ADMIN,
            'partner_id' => $partner->id,
        ]));
    }

    private function request(string $dateOfBirth = '1992-12-16'): CreditProfileRequest
    {
        $borrower = Borrower::create([
            'partner_id' => $this->host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Giselle',
            'last_name' => 'Noel',
            'business_name' => 'Noel Bakery',
            'email' => 'account@example.test',
        ]);

        return CreditProfileRequest::create([
            'borrower_id' => $borrower->id,
            'first_name' => 'Giselle',
            'middle_name' => 'Marie',
            'last_name' => 'Noel',
            'address' => '1 Main St',
            'apt_suite_other' => 'Apt 4',
            'city' => 'Austin',
            'state' => 'Texas',
            'zip' => '78701',
            'ssn' => '123456789',
            'date_of_birth' => $dateOfBirth,
            'phone' => '5125550100',
            'email' => 'giselle@example.test',
        ]);
    }

    private function consent(int $borrowerId): void
    {
        CreditAuthorizationConsent::create([
            'borrower_id' => $borrowerId,
            'authorization_version' => '1.0',
            'authorization_url' => 'https://example.test/disclosure',
            'credit_authorization_text' => 'x',
            'e_consent_text' => 'y',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
            'consented_at' => Carbon::parse('2026-09-20 10:00:00', 'UTC'),
            'pulled_at' => Carbon::parse('2026-09-21 10:00:00', 'UTC'),
        ]);
    }

    /** @return array<string, array{string}> */
    public static function viewerTimezones(): array
    {
        return [
            'no timezone sent' => [''],
            'UTC' => ['UTC'],
            'USA west coast' => ['America/Los_Angeles'],
            'USA east coast' => ['America/New_York'],
            'USA Hawaii' => ['Pacific/Honolulu'],
            'Bangladesh' => ['Asia/Dhaka'],
        ];
    }

    #[Test]
    #[DataProvider('viewerTimezones')]
    public function the_date_of_birth_is_the_stored_day_for_every_viewer(string $timezone): void
    {
        $request = $this->request('1992-12-16');

        $this->getJson("/api/v1/admin/credit-profile-requests/{$request->id}", array_filter(['X-Timezone' => $timezone]))
            ->assertOk()
            ->assertJsonPath('data.date_of_birth', 'December 16, 1992');
    }

    #[Test]
    public function the_first_and_last_day_of_a_year_do_not_slip_into_the_next_one(): void
    {
        $newYearsDay = $this->request('1990-01-01');
        $newYearsEve = $this->request('1989-12-31');

        $this->getJson("/api/v1/admin/credit-profile-requests/{$newYearsDay->id}", ['X-Timezone' => 'America/Los_Angeles'])
            ->assertJsonPath('data.date_of_birth', 'January 1, 1990');
        $this->getJson("/api/v1/admin/credit-profile-requests/{$newYearsEve->id}", ['X-Timezone' => 'Asia/Dhaka'])
            ->assertJsonPath('data.date_of_birth', 'December 31, 1989');
    }

    #[Test]
    public function every_field_of_the_request_is_passed_through_unchanged(): void
    {
        $request = $this->request();

        $data = $this->getJson("/api/v1/admin/credit-profile-requests/{$request->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [
                'id', 'borrower_id', 'first_name', 'middle_name', 'last_name', 'address',
                'apt_suite_other', 'city', 'state', 'zip', 'ssn', 'date_of_birth', 'phone',
                'email', 'created_at', 'updated_at', 'deleted_at', 'borrower',
                'credit_authorizations', 'credit_last_pulled_at',
            ],
            array_keys($data)
        );

        $this->assertSame($request->id, $data['id']);
        $this->assertSame($request->borrower_id, $data['borrower_id']);
        $this->assertSame('Giselle', $data['first_name']);
        $this->assertSame('Marie', $data['middle_name']);
        $this->assertSame('Noel', $data['last_name']);
        $this->assertSame('1 Main St', $data['address']);
        $this->assertSame('Apt 4', $data['apt_suite_other']);
        $this->assertSame('Austin', $data['city']);
        $this->assertSame('Texas', $data['state']);
        $this->assertSame('78701', $data['zip']);
        $this->assertSame('123456789', $data['ssn']);
        $this->assertSame('5125550100', $data['phone']);
        $this->assertSame('giselle@example.test', $data['email']);
        $this->assertNull($data['deleted_at']);

        // Timestamps are real instants and stay as the model serialises them.
        $fresh = $request->fresh();
        $this->assertSame($fresh->created_at->toJSON(), $data['created_at']);
        $this->assertSame($fresh->updated_at->toJSON(), $data['updated_at']);
    }

    #[Test]
    public function the_borrower_account_travels_with_the_request(): void
    {
        $request = $this->request();

        $this->getJson("/api/v1/admin/credit-profile-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.borrower.id', $request->borrower_id)
            ->assertJsonPath('data.borrower.business_name', 'Noel Bakery')
            ->assertJsonPath('data.borrower.first_name', 'Giselle')
            ->assertJsonPath('data.borrower.last_name', 'Noel')
            ->assertJsonPath('data.borrower.email', 'account@example.test');
    }

    #[Test]
    public function the_host_partner_sees_the_authorisations_and_the_last_pull(): void
    {
        $request = $this->request();
        $this->consent($request->borrower_id);

        $this->getJson("/api/v1/admin/credit-profile-requests/{$request->id}", ['X-Timezone' => 'UTC'])
            ->assertOk()
            ->assertJsonCount(1, 'data.credit_authorizations')
            ->assertJsonPath('data.credit_authorizations.0.authorization_version', '1.0')
            ->assertJsonPath(
                'data.credit_last_pulled_at',
                CreditAuthorizationConsent::lastPulledForBorrower($request->borrower_id)
            );
    }

    #[Test]
    public function a_request_with_no_authorisations_has_an_empty_list_and_no_last_pull(): void
    {
        $request = $this->request();

        $this->getJson("/api/v1/admin/credit-profile-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.credit_authorizations', [])
            ->assertJsonPath('data.credit_last_pulled_at', null);
    }

    #[Test]
    public function a_non_host_partner_gets_no_authorisation_records(): void
    {
        $request = $this->request();
        $this->consent($request->borrower_id);

        $partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Other Partner',
            'full_name' => 'Other Owner',
            'email' => 'other@example.test',
        ]);
        $this->actingAsAdminOf($partner, 'other-admin@example.test');

        $this->getJson("/api/v1/admin/credit-profile-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.credit_authorizations', [])
            ->assertJsonPath('data.credit_last_pulled_at', null)
            ->assertJsonPath('data.date_of_birth', 'December 16, 1992');
    }

    #[Test]
    public function an_unknown_request_is_not_found(): void
    {
        $this->getJson('/api/v1/admin/credit-profile-requests/999999')->assertNotFound();
    }
}
