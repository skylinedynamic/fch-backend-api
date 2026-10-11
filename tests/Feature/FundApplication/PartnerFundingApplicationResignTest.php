<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mirrors FundingApplicationResignTest, but for a WL Partner editing an
 * application on the borrower's behalf via PartnerFundingApplicationService.
 * Same rule: only a real field change on an already-submitted application
 * should invalidate the signature and require a resign.
 */
class PartnerFundingApplicationResignTest extends TestCase
{
    use RefreshDatabase;

    private int $partnerId;
    private int $borrowerId;
    private int $fundingRequestId;
    private int $applicationId;

    private array $businessInfoPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'phone_number' => '(111) 111-1111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'business_name' => 'Alpha Tech Solutions',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'phone_number' => '(546) 456-4520',
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fundingSequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit',
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
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'use_of_funds' => 'Accounts Receivable',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->businessInfoPayload = [
            'id' => null, // filled in once the application row exists
            'funding_request_id' => $this->fundingRequestId,
            'full_name' => 'Michael Chen',
            'business_name' => 'Alpha Tech Solutions',
            'business_email' => 'contact@alphatech.example.com',
            'business_phone_no' => '(546) 456-4520',
            'business_country' => 'USA',
            'business_country_code' => 'US',
            'business_address' => '500 Innovation Dr',
            'business_city' => 'Vancouver',
            'business_state' => 'Washington',
            'business_postal_code' => '94107',
            'industry_type' => 'Real Estate (Non-Lending)',
            'entity_type' => 'Sole Proprietor',
            'years_in_business' => '7 Years 1 Month 3 Days',
            'number_of_employees' => null,
        ];

        $this->applicationId = DB::table('funding_applications')->insertGetId(array_merge(
            array_diff_key($this->businessInfoPayload, ['id' => null]),
            [
                'application_number' => 'FUND-TEST-2',
                'borrower_id' => $this->borrowerId,
                'partner_id' => $this->partnerId,
                'is_partner_prepared' => true,
                'status' => FundingApplication::PENDING,
                'signature_data' => json_encode(['signature_name' => 'Michael Chen', 'signed_at' => now()->toDateTimeString()]),
                'attestation_authorization' => true,
                'requires_resign' => false,
                'is_business_info_saved' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ));
        $this->businessInfoPayload['id'] = $this->applicationId;

        $userId = DB::table('users')->insertGetId([
            'name' => 'Pat Partner',
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => \App\Models\Partner\Partner::class,
            'entity_id' => $this->partnerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::findOrFail($userId), [], 'api');
    }

    #[Test]
    public function resaving_a_step_with_no_real_changes_does_not_force_a_resign(): void
    {
        $payload = $this->businessInfoPayload;
        $payload['number_of_employees'] = '';

        $this->postJson('/api/v1/common/funding-application/business-info', $payload)
            ->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::PENDING, $application->status);
        $this->assertFalse((bool) $application->requires_resign);
        $this->assertNotNull($application->signature_data);
    }

    #[Test]
    public function changing_a_field_on_a_submitted_application_forces_a_resign(): void
    {
        $payload = $this->businessInfoPayload;
        $payload['business_name'] = 'Alpha Tech Solutions LLC';

        $this->postJson('/api/v1/common/funding-application/business-info', $payload)
            ->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::DRAFT, $application->status);
        $this->assertTrue((bool) $application->requires_resign);
        $this->assertNull($application->signature_data);
        $this->assertFalse((bool) $application->attestation_authorization);
        $this->assertSame('Alpha Tech Solutions LLC', $application->business_name);
    }
}
