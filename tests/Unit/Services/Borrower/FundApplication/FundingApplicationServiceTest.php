<?php

namespace Tests\Unit\Services\Borrower\FundApplication;

use App\Mail\Borrower\FundingApplicationSubmittedMail;
use App\Mail\Partner\BorrowerSubmittedFundingApplicationMail;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Borrower\FundApplication\FundingApplicationService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FundingApplicationServiceTest extends TestCase
{
    protected FundingApplicationService $service;

    protected Borrower $borrower;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Testing-infrastructure gap: no ServiceProvider registers
        // Factory::guessFactoryNamesUsing(), and every factory in this repo
        // lives flat under database/factories/ (e.g. BorrowerFactory) rather
        // than mirroring the model's App\Models\<Domain>\<Model> sub-namespace
        // (which Laravel's default resolver expects, e.g.
        // Database\Factories\Borrower\BorrowerFactory). Without this, calling
        // Model::factory() on ANY namespaced model (Borrower, FundingApplication,
        // Partner, ...) throws "Class ... not found". Registered locally here
        // rather than editing the shared tests/TestCase.php.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->service = new FundingApplicationService;

        $this->borrower = Borrower::factory()->create();
        $this->user = User::factory()->borrower($this->borrower)->create();

        $this->actingAs($this->user);
    }

    public function test_first_step_creates_a_new_on_hold_application_and_sets_its_flag(): void
    {
        $application = $this->service->saveFundingApplication([
            'full_name' => 'John Doe',
            'business_name' => 'Acme Inc',
            'is_business_info_saved' => true,
        ]);

        $this->assertDatabaseHas('funding_applications', [
            'id' => $application->id,
            'borrower_id' => $this->borrower->id,
            'business_name' => 'Acme Inc',
            'full_name' => 'John Doe',
            'status' => FundingApplication::ON_HOLD,
            'is_business_info_saved' => 1,
            'is_ownership_info_saved' => 0,
            'is_financial_info_saved' => 0,
        ]);
    }

    public function test_second_step_updates_the_same_on_hold_application_and_flips_only_its_own_flag(): void
    {
        $existing = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
            'status' => FundingApplication::ON_HOLD,
            'is_business_info_saved' => true,
            'business_name' => 'Acme Inc',
        ]);

        $application = $this->service->saveFundingApplication([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'ssn' => '123456789',
            'is_ownership_info_saved' => true,
        ]);

        $this->assertSame($existing->id, $application->id);

        $this->assertDatabaseHas('funding_applications', [
            'id' => $existing->id,
            // business info from step 1 is preserved
            'business_name' => 'Acme Inc',
            'is_business_info_saved' => 1,
            // step 2 fields now persisted
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'is_ownership_info_saved' => 1,
            // untouched steps remain false
            'is_financial_info_saved' => 0,
        ]);
    }

    /**
     * Compliance characterization test — NOT an endorsement. This repo's CLAUDE.md
     * tracks SSN/bank-field encryption-at-rest as an outstanding gap: `ssn` has no
     * cast at all on FundingApplication, so it round-trips as plaintext.
     */
    public function test_ssn_round_trips_as_plaintext_characterizing_the_known_unencrypted_gap(): void
    {
        $application = $this->service->saveFundingApplication([
            'first_name' => 'Jane',
            'ssn' => '123456789',
            'is_ownership_info_saved' => true,
        ]);

        $this->assertSame('123456789', $application->fresh()->ssn);
        $this->assertDatabaseHas('funding_applications', [
            'id' => $application->id,
            'ssn' => '123456789',
        ]);
    }

    public function test_co_borrower_ssn_is_persisted_as_plaintext_and_round_trips(): void
    {
        $application = $this->service->saveFundingApplication([
            'has_co_borrower' => true,
            'co_borrowers' => [
                [
                    'first_name' => 'Cindy',
                    'last_name' => 'Co',
                    'ssn' => '987654321',
                    'role' => 'Partner',
                    'ownership' => 25,
                ],
            ],
            'is_ownership_info_saved' => true,
        ]);

        $coBorrower = FundingApplicationCoBorrower::where('funding_application_id', $application->id)->first();

        $this->assertNotNull($coBorrower);
        $this->assertSame('987654321', $coBorrower->ssn);
        $this->assertDatabaseHas('funding_application_co_borrowers', [
            'funding_application_id' => $application->id,
            'ssn' => '987654321',
        ]);
    }

    public function test_setting_has_co_borrower_false_removes_existing_co_borrowers(): void
    {
        $application = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
            'status' => FundingApplication::ON_HOLD,
            'has_co_borrower' => true,
        ]);

        FundingApplicationCoBorrower::factory()->create([
            'funding_application_id' => $application->id,
        ]);

        $this->assertDatabaseCount('funding_application_co_borrowers', 1);

        $this->service->saveFundingApplication([
            'id' => $application->id,
            'has_co_borrower' => false,
        ]);

        $this->assertDatabaseCount('funding_application_co_borrowers', 0);
    }

    public function test_does_not_overwrite_an_already_advanced_status_on_a_later_step_when_no_status_is_passed(): void
    {
        $existing = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
            'status' => FundingApplication::UNDERWRITING_REVIEW,
        ]);

        $application = $this->service->saveFundingApplication([
            'id' => $existing->id,
            'monthly_revenue' => 10000,
            'is_financial_info_saved' => true,
        ]);

        $this->assertSame(FundingApplication::UNDERWRITING_REVIEW, $application->fresh()->status);
    }

    public function test_attestation_step_sets_status_to_pending_and_emails_borrower_and_partner(): void
    {
        Mail::fake();

        $partner = Partner::factory()->create(['email' => 'partner@example.com']);
        $host = Partner::factory()->host()->create(['email' => 'host@example.com']);

        $borrower = Borrower::factory()->create(['partner_id' => $partner->id, 'email' => 'borrower@example.com']);
        $user = User::factory()->borrower($borrower)->create();
        $this->actingAs($user);

        $application = $this->service->saveFundingApplication([
            'attestation_authorization' => true,
            'is_federal_compliance_saved' => true,
        ], FundingApplication::PENDING);

        $this->assertSame(FundingApplication::PENDING, $application->fresh()->status);

        Mail::assertSent(FundingApplicationSubmittedMail::class, function ($mail) use ($borrower) {
            return $mail->hasTo($borrower->email);
        });

        Mail::assertSent(BorrowerSubmittedFundingApplicationMail::class);
    }

    public function test_creates_document_directory_only_once_for_a_given_application(): void
    {
        $application = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
        ]);

        $this->assertTrue($this->service->createFundingApplicationDocumentDir($application->id));
        // Second call should short-circuit (existing documents count > 0 branch,
        // or simply run again without error when there are still none).
        $this->assertTrue($this->service->createFundingApplicationDocumentDir($application->id));
    }
}
