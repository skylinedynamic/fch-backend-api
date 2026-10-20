<?php

namespace Tests\Feature\FundApplication;

use App\Mail\Borrower\FundingApplicationSubmittedMail as BorrowerFundingApplicationSubmittedMail;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\Borrower\FundApplication\FundingApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Once a borrower's Funding Application is submitted (status Submitted - Pending
 * Review), editing it must invalidate the prior signature and put it back in
 * Draft - but only when the edit actually changes something. The wizard
 * prefills every step with the currently saved values, so re-saving a step
 * untouched must NOT force a resign; only a real field change may.
 *
 * These assertions live at Feature level (not Unit) because the behavior lives
 * inside FundingApplicationService::saveFundingApplication(), which reads
 * auth()->user() and request() directly rather than taking them as arguments.
 */
class FundingApplicationResignTest extends TestCase
{
    use RefreshDatabase;

    private int $borrowerId;
    private int $fundingRequestId;
    private int $applicationId;

    private array $businessInfoPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $partnerId = DB::table('partners')->insertGetId([
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
            'partner_id' => $partnerId,
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
            'partner_id' => $partnerId,
            'funding_sequence_id' => $fundingSequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'use_of_funds' => 'Accounts Receivable',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->businessInfoPayload = [
            'id' => null, // filled in per-test once the application row exists
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

        // A previously-submitted application: signed, Pending Review, nothing pending resign.
        $this->applicationId = DB::table('funding_applications')->insertGetId(array_merge(
            array_diff_key($this->businessInfoPayload, ['id' => null]),
            [
                'application_number' => 'FUND-TEST-1',
                'borrower_id' => $this->borrowerId,
                'partner_id' => $partnerId,
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
            'name' => 'Michael Chen',
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::BORROWER,
            'entity_type' => \App\Models\Borrower\Borrower::class,
            'entity_id' => $this->borrowerId,
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::findOrFail($userId), [], 'api');
    }

    #[Test]
    public function resaving_a_step_with_no_real_changes_does_not_force_a_resign(): void
    {
        // The wizard round-trips this exact data back to the server, including an
        // untouched optional field ('') for what is stored as null.
        $payload = $this->businessInfoPayload;
        $payload['number_of_employees'] = '';

        $this->postJson('/api/v1/borrower/funding-application/business-info', $payload)
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
        $payload['business_name'] = 'Alpha Tech Solutions LLC'; // an actual edit

        $this->postJson('/api/v1/borrower/funding-application/business-info', $payload)
            ->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::DRAFT, $application->status);
        $this->assertTrue((bool) $application->requires_resign);
        $this->assertNull($application->signature_data);
        $this->assertFalse((bool) $application->attestation_authorization);
        $this->assertSame('Alpha Tech Solutions LLC', $application->business_name, 'the actual edit must still be persisted');
    }

    /**
     * Defect: the "application submitted" bell and email went out after any
     * save that left the application at Submitted - Pending Review, not just
     * the Submit. Paging through the steps of an already-submitted application
     * (Save & Next on each) sent the borrower, the partner and the host the
     * same "submitted" notification and email again for every step.
     */
    #[Test]
    public function resaving_steps_of_a_submitted_application_sends_no_submitted_notification(): void
    {
        Notification::fake();
        Mail::fake();

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/v1/borrower/funding-application/business-info', $this->businessInfoPayload)
                ->assertOk();
        }

        $this->assertSame(FundingApplication::PENDING, FundingApplication::find($this->applicationId)->status);
        Notification::assertNotSentTo($this->borrowerUser(), DynamicNotification::class);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    #[Test]
    public function submitting_sends_the_submitted_notification_and_email_once(): void
    {
        Notification::fake();
        Mail::fake();
        FundingApplication::find($this->applicationId)->update([
            'status' => FundingApplication::DRAFT,
            'signature_data' => null,
            'attestation_authorization' => false,
            'requires_resign' => true,
        ]);

        // What FundingApplicationController::saveAttestationAndAuthorization does on Submit.
        app(FundingApplicationService::class)->saveFundingApplication([
            'id' => $this->applicationId,
            'funding_request_id' => $this->fundingRequestId,
            'signature_data' => ['signature_name' => 'Michael Chen', 'signed_at' => now()->toDateTimeString()],
            'attestation_authorization' => true,
            'requires_resign' => false,
        ], FundingApplication::PENDING);

        Notification::assertSentToTimes($this->borrowerUser(), DynamicNotification::class, 1);
        Notification::assertSentTo(
            $this->borrowerUser(),
            DynamicNotification::class,
            fn (DynamicNotification $n) => $n->typeKey === 'borrower.funding_application.submitted'
        );
        Mail::assertQueued(BorrowerFundingApplicationSubmittedMail::class, 1);
    }

    private function borrowerUser(): User
    {
        return User::where('entity_id', $this->borrowerId)->where('user_type', User::BORROWER)->firstOrFail();
    }

    #[Test]
    public function resigning_clears_the_flag_and_resubmits(): void
    {
        // Put the application in the "needs resign" state a real edit would produce.
        FundingApplication::find($this->applicationId)->update([
            'status' => FundingApplication::DRAFT,
            'signature_data' => null,
            'attestation_authorization' => false,
            'requires_resign' => true,
        ]);

        // This mirrors what FundingApplicationController::saveAttestationAndAuthorization
        // does once the borrower has re-signed: pass status explicitly and clear the flag.
        app(FundingApplicationService::class)->saveFundingApplication([
            'id' => $this->applicationId,
            'funding_request_id' => $this->fundingRequestId,
            'signature_data' => ['signature_name' => 'Michael Chen', 'signed_at' => now()->toDateTimeString()],
            'attestation_authorization' => true,
            'requires_resign' => false,
        ], FundingApplication::PENDING);

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::PENDING, $application->status);
        $this->assertFalse((bool) $application->requires_resign);
        $this->assertNotNull($application->signature_data);
    }
}
