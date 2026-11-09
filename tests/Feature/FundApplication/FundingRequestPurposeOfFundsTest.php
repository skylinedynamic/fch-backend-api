<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesFundingTypes;
use Tests\TestCase;

/**
 * Step 4 of the funding application, Funding Request: Purpose of Funds takes
 * several purposes. The borrower portal sends them joined (", ") in the one
 * purpose_of_funds string, which every screen already shows as text.
 *
 * The column was varchar(255) behind a max:500 rule: a long list passed
 * validation and then failed on insert in MySQL. It now holds the 500.
 */
class FundingRequestPurposeOfFundsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFundingTypes;

    private int $applicationId;
    private int $fundingRequestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFundingTypes();

        $partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Term Loans',
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->fundingRequestId = DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'funding_sequence_id' => $sequenceId,
            'funding_type' => 'Term Loan',
            'requested_amount' => 30000,
            'use_of_funds' => 'Accounts Receivable',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-TEST-' . uniqid(),
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'funding_request_id' => $this->fundingRequestId,
            'status' => FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'name' => 'Signed In',
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::BORROWER,
            'entity_type' => \App\Models\Borrower\Borrower::class,
            'entity_id' => $borrowerId,
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::findOrFail($userId), [], 'api');
    }

    #[Test]
    public function several_purposes_are_saved_and_given_back_as_sent(): void
    {
        // "Furniture, Fixtures & Equipment (FF&E)" carries its own comma.
        $purposes = 'Working Capital, Furniture, Fixtures & Equipment (FF&E), Inventory, Other';

        $this->postJson('/api/v1/borrower/funding-application/funding-request', [
            'id' => $this->applicationId,
            'funding_request_id' => $this->fundingRequestId,
            'requested_amount' => 50000,
            'purpose_of_funds' => $purposes,
            'purpose_note' => 'Trade show booth',
            'approved_funding' => 'Term Loan',
            'preferred_funding_type' => 'Term Loan',
        ])->assertOk()->assertJsonPath('data.purpose_of_funds', $purposes);

        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertSame($purposes, $application->purpose_of_funds);
        $this->assertSame('Trade show booth', $application->purpose_note);
    }

    #[Test]
    public function the_column_holds_as_much_as_the_rule_allows(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('SQLite neither keeps nor enforces a varchar length.');
        }

        $column = collect(Schema::getColumns('funding_applications'))->firstWhere('name', 'purpose_of_funds');

        $this->assertSame('varchar(500)', $column['type']);
        $this->assertTrue($column['nullable']);
    }
}
