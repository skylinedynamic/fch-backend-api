<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use App\Services\FCH\Common\Application\SignatureRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Signing in the borrower portal and in the partner portal records who signed
 * and how, from where, the policy versions in force, and keeps the signed copy.
 */
class SigningMethodTest extends TestCase
{
    use RefreshDatabase;

    private int $applicationId;
    private int $fundingRequestId;
    private int $userId;

    public static function portals(): array
    {
        return [
            'borrower portal' => ['/api/v1/borrower/funding-application/attestation-authorization', User::BORROWER, SignatureRecorder::METHOD_BORROWER_PORTAL],
            'partner portal' => ['/api/v1/common/funding-application/attestation-authorization', User::PARTNER, SignatureRecorder::METHOD_PARTNER_PORTAL],
        ];
    }

    private function signIn(string $userType): void
    {
        Storage::fake('public');

        $partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Ava',
            'last_name' => 'Ortiz',
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
            'use_of_funds' => 'Working Capital',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-SIGN-' . uniqid(),
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'funding_request_id' => $this->fundingRequestId,
            'first_name' => 'Ava',
            'last_name' => 'Ortiz',
            'status' => FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $isBorrower = $userType === User::BORROWER;
        $this->userId = DB::table('users')->insertGetId([
            'name' => $isBorrower ? 'Ava Ortiz' : 'Pat Partner',
            'email' => ($isBorrower ? 'ava-' : 'pat-') . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'entity_type' => $isBorrower ? \App\Models\Borrower\Borrower::class : \App\Models\Partner\Partner::class,
            'entity_id' => $isBorrower ? $borrowerId : $partnerId,
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::findOrFail($this->userId), [], 'api');
    }

    private function sign(string $url)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->postJson($url, [
            'id' => $this->applicationId,
            'funding_request_id' => $this->fundingRequestId,
            'signature_name' => 'Ava Ortiz',
            'timezone' => 'America/Chicago',
            'esign_consent' => true,
            'terms_consent' => true,
            'credit_consent' => true,
            'legal_binding_consent' => true,
            // What a browser might claim; the server records its own versions.
            'terms_version' => '9.9',
            'application_version' => '2026.09.03',
        ]);
    }

    #[Test]
    #[DataProvider('portals')]
    public function the_signature_records_who_signed_how_and_from_where(string $url, string $userType, string $method): void
    {
        $this->signIn($userType);

        $this->sign($url)->assertOk();

        $signature = FundingApplication::findOrFail($this->applicationId)->signature_data;
        $this->assertSame($method, $signature['signing_method']);
        $this->assertSame($this->userId, $signature['signer_account']['user_id']);
        $this->assertSame('198.51.100.23', $signature['ip_address']);
        $this->assertSame('America/Chicago', $signature['timezone']);
        $this->assertStringEndsWith('+00:00', $signature['signed_at']);
        $this->assertSame(array_keys(config('funding_application.signing.policies')), array_keys($signature['policy_versions']));
        $this->assertArrayNotHasKey('terms_version', $signature);
        $this->assertSame('2026.09.03', $signature['form_version']);
    }

    #[Test]
    #[DataProvider('portals')]
    public function the_signed_copy_is_kept_as_version_one(string $url, string $userType, string $method): void
    {
        $this->signIn($userType);

        $this->sign($url)->assertOk();

        $signature = FundingApplication::findOrFail($this->applicationId)->signature_data;
        $this->assertSame(1, $signature['application_version']);
        Storage::disk('uploads')->assertExists($signature['signed_application_path']);
        Storage::disk('uploads')->assertExists($signature['certificate_path']);
    }
}
