<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesFundingTypes;
use Tests\TestCase;

/**
 * Step 4 of the funding application wizard, Funding Request: besides the
 * preferred funding type, the other funding types the applicant would also
 * consider - in the borrower portal and in the partner portal alike.
 */
class FundingRequestAdditionalFundingTypesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFundingTypes;

    private int $partnerId;
    private int $borrowerId;
    private int $fundingRequestId;
    private int $applicationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFundingTypes();
    }

    public static function portals(): array
    {
        return [
            'borrower portal' => ['/api/v1/borrower/funding-application/funding-request', User::BORROWER],
            'partner portal' => ['/api/v1/common/funding-application/funding-request', User::PARTNER],
        ];
    }

    private function signIn(string $userType): void
    {
        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fundingSequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Term Loans',
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fundingRequestId = DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'funding_sequence_id' => $fundingSequenceId,
            'funding_type' => 'Term Loan',
            'requested_amount' => 30000,
            'use_of_funds' => 'Accounts Receivable',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-TEST-' . uniqid(),
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'funding_request_id' => $this->fundingRequestId,
            'status' => FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $isBorrower = $userType === User::BORROWER;
        $userId = DB::table('users')->insertGetId([
            'name' => 'Signed In',
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'entity_type' => $isBorrower ? \App\Models\Borrower\Borrower::class : \App\Models\Partner\Partner::class,
            'entity_id' => $isBorrower ? $this->borrowerId : $this->partnerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::findOrFail($userId), [], 'api');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => $this->applicationId,
            'funding_request_id' => $this->fundingRequestId,
            'requested_amount' => 50000,
            'purpose_of_funds' => 'Working Capital',
            'approved_funding' => 'Term Loan',
            'preferred_funding_type' => 'Term Loan',
            'additional_funding_types' => ['SBA Financing', 'Business Line of Credit'],
        ], $overrides);
    }

    #[Test]
    #[DataProvider('portals')]
    public function the_additional_funding_types_are_saved_and_given_back(string $url, string $userType): void
    {
        $this->signIn($userType);

        $this->postJson($url, $this->payload())
            ->assertOk()
            ->assertJsonPath('data.additional_funding_types', ['SBA Financing', 'Business Line of Credit']);

        $this->assertSame(['SBA Financing', 'Business Line of Credit'], FundingApplication::findOrFail($this->applicationId)->additional_funding_types);

        $this->getJson("{$url}?id={$this->applicationId}&funding_request_id={$this->fundingRequestId}")
            ->assertOk()
            ->assertJsonPath('data.additional_funding_types', ['SBA Financing', 'Business Line of Credit']);
    }

    #[Test]
    #[DataProvider('portals')]
    public function they_are_optional(string $url, string $userType): void
    {
        $this->signIn($userType);

        $payload = $this->payload();
        unset($payload['additional_funding_types']);

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.additional_funding_types', []);
    }

    #[Test]
    #[DataProvider('portals')]
    public function the_preferred_type_cannot_also_be_an_additional_one(string $url, string $userType): void
    {
        $this->signIn($userType);

        $this->postJson($url, $this->payload(['additional_funding_types' => ['SBA Financing', 'Term Loan']]))
            ->assertStatus(422);
    }

    #[Test]
    #[DataProvider('portals')]
    public function an_additional_type_must_come_from_the_list_and_appear_once(string $url, string $userType): void
    {
        $this->signIn($userType);

        $this->postJson($url, $this->payload(['additional_funding_types' => ['Something Else']]))->assertStatus(422);
        $this->postJson($url, $this->payload(['additional_funding_types' => ['SBA Financing', 'SBA Financing']]))->assertStatus(422);
    }
}
