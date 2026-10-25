<?php

namespace Tests\Feature\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Models\Walkthrough\WalkthroughProgress;
use App\Services\Borrower\Profile\OnboardingStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The borrower's Getting Started step, reported by the profile completion
 * endpoint and kept in `walkthrough_progress` (the borrower's
 * `borrower_onboarding` row), not on the borrowers table.
 *
 * The journey is: submit the credit profile -> upload documents -> submit every
 * Tier 0 task with Verify All -> wait while FCH verifies them and the credit
 * profile -> results ready, choose a path. It starts once the borrower has paid and
 * completed their profile. The borrower portal shows one suggestion
 * popup for whichever step is first not done, so the order matters as much as
 * each check.
 */
#[CoversClass(OnboardingStepService::class)]
class OnboardingStepTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private Borrower $borrower;
    private Tier $tier0;
    private Tier $tier1;
    /** @var TierTask[] */
    private array $tier0Tasks;
    /** The document categories on the Documents page; Others is optional. */
    private BorrowerDocType $bankStatements;
    private BorrowerDocType $einLetter;
    private BorrowerDocType $others;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->tier0 = Tier::create(['code' => 'T0', 'name' => 'Tier 0', 'title' => 'Foundation', 'order' => 1]);
        $this->tier1 = Tier::create(['code' => 'T1', 'name' => 'Tier 1', 'title' => 'Starter', 'order' => 2]);
        $this->tier0Tasks = [
            TierTask::create(['tier_id' => $this->tier0->id, 'title' => 'EIN', 'order' => 1]),
            TierTask::create(['tier_id' => $this->tier0->id, 'title' => 'Bank account', 'order' => 2]),
        ];

        $this->bankStatements = BorrowerDocType::create(['name' => 'Business Bank Statements', 'sort_order' => 1]);
        $this->einLetter = BorrowerDocType::create(['name' => 'EIN Letter', 'sort_order' => 2]);
        $this->others = BorrowerDocType::create(['name' => 'Others', 'sort_order' => 3]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
            'partner_payment_for_me' => true,
            'current_tier' => $this->tier0->id,
        ]);

        $user = User::create([
            'name' => 'Odessa Mccall',
            'email' => 'odessa@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'partner_id' => $this->partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]);

        Passport::actingAs($user);
    }

    // -- fixtures ------------------------------------------------------------

    private function subscribe(string $status = Subscription::STATUS_ACTIVE, ?string $endDate = null): void
    {
        Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'status' => $status,
            'subscription_end_date' => $endDate,
        ]);
    }

    private function completeProfile(): void
    {
        $this->borrower->update([
            'phone_number' => '5125550100',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78701',
            'country' => 'US',
            'home_address' => '1 Main St',
            'last_4_ssn' => '6789',
            'date_of_birth' => '1986-09-01',
            'business_name' => 'Mccall LLC',
            'business_ein' => '12-3456789',
            'business_address' => '2 Main St',
            'business_email' => 'hello@mccall.example',
            'business_country' => 'United States',
            'business_entity_type' => 'LLC',
            'business_industry_type' => 'Retail',
            'business_state' => 'TX',
            'business_city' => 'Austin',
            'business_postal_code' => '78701',
            'business_start_date' => '2020-01-01',
            'currency' => 'USD',
            'monthly_revenue' => 10000,
            'annual_revenue' => 120000,
            'annual_personal_income' => 90000,
            'average_monthly_deposits' => 9000,
            'years_in_business' => 5,
        ]);
    }

    private function submitCreditProfile(): void
    {
        CreditProfileRequest::create([
            'borrower_id' => $this->borrower->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'address' => '1 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'ssn' => '123456789',
            'date_of_birth' => '1986-09-01',
            'phone' => '5125550100',
            'email' => 'odessa@example.test',
        ]);
    }

    private function uploadDocument(BorrowerDocType $type, string $status = BorrowerDocument::STATUS_UPLOADED): void
    {
        BorrowerDocument::create([
            'borrower_id' => $this->borrower->id,
            'document_type_id' => $type->id,
            'file_name' => 'document.pdf',
            'file_path' => 'documents/document.pdf',
            'status' => $status,
        ]);
    }

    /** A document in every required category: all but Others. */
    private function uploadAllDocuments(): void
    {
        $this->uploadDocument($this->bankStatements);
        $this->uploadDocument($this->einLetter);
    }

    /** Tier 0 task requests, by status, one per task in order. */
    private function tier0Requests(string ...$statuses): void
    {
        foreach ($statuses as $i => $status) {
            BorrowerTaskVerificationRequest::create([
                'borrower_id' => $this->borrower->id,
                'partner_id' => $this->partner->id,
                'tier_id' => $this->tier0->id,
                'task_id' => $this->tier0Tasks[$i]->id,
                'status' => $status,
            ]);
        }
    }

    private function startJourney(): void
    {
        $this->subscribe();
        $this->completeProfile();
    }

    /** The borrower's part done: credit profile, every Tier 0 task, every document. */
    private function resultsReady(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);
    }

    /** The borrower's walkthrough row, or null if none has been written. */
    private function progress(): ?WalkthroughProgress
    {
        return WalkthroughProgress::where('entity_type', Borrower::class)
            ->where('entity_id', $this->borrower->id)
            ->where('walkthrough', WalkthroughProgress::BORROWER_ONBOARDING)
            ->first();
    }

    private function onboarding(): array
    {
        return $this->getJson('/api/v1/borrower/profile/completion')
            ->assertOk()
            ->json('data.onboarding');
    }

    // -- when the walkthrough starts -----------------------------------------
    //
    // Paying and completing the profile have their own prompts in the portal
    // (the pending-invoice banner and the Complete Your Profile modal), so the
    // walkthrough only begins once both are done.

    #[Test]
    public function a_borrower_whose_subscription_is_not_active_has_not_started(): void
    {
        $this->subscribe(Subscription::STATUS_PENDING);
        $this->completeProfile();

        $this->assertNull($this->onboarding()['current_step']);
    }

    #[Test]
    public function a_borrower_whose_paid_period_has_ended_has_not_started(): void
    {
        $this->subscribe(Subscription::STATUS_ACTIVE, now()->subDay()->toDateString());
        $this->completeProfile();

        $this->assertNull($this->onboarding()['current_step']);
    }

    #[Test]
    public function a_white_label_borrower_has_not_started_until_their_enrollment_fee_is_covered(): void
    {
        $this->partner->update(['partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL]);
        $this->borrower->update(['partner_payment_for_me' => false]);
        $this->subscribe();
        $this->completeProfile();

        $this->assertNull($this->onboarding()['current_step']);
    }

    #[Test]
    public function a_free_trial_borrower_gets_the_short_walkthrough(): void
    {
        // The walkthrough proper is for paid borrowers: on the free trial it is
        // only the profile and the credit profile.
        $this->borrower->update(['is_free_trail' => true]);
        $this->subscribe();
        $this->completeProfile();

        $onboarding = $this->onboarding();
        $this->assertSame('free_trial', $onboarding['flow']);
        $this->assertSame('credit_profile', $onboarding['current_step']);
        $this->assertSame(2, $onboarding['total_steps']);
    }

    #[Test]
    public function a_paid_borrower_with_gaps_in_their_profile_starts_by_completing_it(): void
    {
        $this->subscribe();

        $onboarding = $this->onboarding();
        $this->assertSame('complete_profile', $onboarding['current_step']);
        $this->assertSame(0, $onboarding['completed_steps']);
        $this->assertSame(5, $onboarding['total_steps']);
    }

    #[Test]
    public function a_complete_profile_moves_a_paid_borrower_on_to_the_credit_profile(): void
    {
        $this->subscribe();
        $this->completeProfile();

        $onboarding = $this->onboarding();
        $this->assertSame('credit_profile', $onboarding['current_step']);
        $this->assertSame(1, $onboarding['completed_steps']);
    }

    #[Test]
    public function a_free_trial_borrower_also_starts_by_completing_their_profile(): void
    {
        $this->borrower->update(['is_free_trail' => true]);

        $onboarding = $this->onboarding();
        $this->assertSame('complete_profile', $onboarding['current_step']);
        $this->assertSame(2, $onboarding['total_steps']);
    }

    #[Test]
    public function a_borrower_without_a_subscription_record_is_not_held_back_by_it(): void
    {
        // Matches the portal's existing rule: no subscription, nothing to lock.
        $this->completeProfile();

        $this->assertSame('credit_profile', $this->onboarding()['current_step']);
    }

    // -- what counts as a complete profile ------------------------------------
    //
    // The Business Details form refuses to save without these, so a profile
    // without them isn't complete either.

    #[Test]
    public function a_missing_business_email_leaves_business_details_incomplete(): void
    {
        $this->subscribe();
        $this->completeProfile();
        $this->borrower->update(['business_email' => null]);

        $business = $this->getJson('/api/v1/borrower/profile/completion')->assertOk()->json('data.business');

        $this->assertFalse($business['is_complete']);
        $this->assertSame(['business_email'], $business['missing_fields']);
        $this->assertSame('complete_profile', $this->onboarding()['current_step']);
    }

    #[Test]
    public function a_blank_business_email_counts_as_missing_too(): void
    {
        $this->subscribe();
        $this->completeProfile();
        $this->borrower->update(['business_email' => '  ']);

        $this->assertContains(
            'business_email',
            $this->getJson('/api/v1/borrower/profile/completion')->json('data.business.missing_fields')
        );
    }

    #[Test]
    public function a_missing_business_country_leaves_business_details_incomplete(): void
    {
        $this->subscribe();
        $this->completeProfile();
        $this->borrower->update(['business_country' => null]);

        $this->assertSame(
            ['business_country'],
            $this->getJson('/api/v1/borrower/profile/completion')->json('data.business.missing_fields')
        );
    }

    // -- the order of the journey -------------------------------------------

    #[Test]
    public function a_paid_borrower_with_a_complete_profile_starts_with_the_credit_profile_form(): void
    {
        $this->startJourney();

        $this->assertSame('credit_profile', $this->onboarding()['current_step']);
    }

    #[Test]
    public function a_submitted_credit_profile_asks_for_verify_all_next(): void
    {
        // No waiting for the credit profile to be verified first.
        $this->startJourney();
        $this->submitCreditProfile();

        $onboarding = $this->onboarding();
        $this->assertSame('verify_all', $onboarding['current_step']);
        $this->assertSame(2, $onboarding['completed_steps']);
    }

    #[Test]
    public function documents_come_after_verify_all(): void
    {
        // Even with documents already in, Tier 0 comes first.
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();

        $this->assertSame('verify_all', $this->onboarding()['current_step']);
    }

    #[Test]
    public function submitting_tier_0_moves_the_borrower_on_to_upload_documents(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);

        $onboarding = $this->onboarding();
        $this->assertSame('upload_documents', $onboarding['current_step']);
        $this->assertSame(3, $onboarding['completed_steps']);
    }

    #[Test]
    public function one_document_is_not_enough_to_move_on(): void
    {
        // The step stays until every required category has a document.
        $this->startJourney();
        $this->submitCreditProfile();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);
        $this->uploadDocument($this->bankStatements);

        $onboarding = $this->onboarding();
        $this->assertSame('upload_documents', $onboarding['current_step']);
        $this->assertSame(3, $onboarding['completed_steps']);
    }

    #[Test]
    public function the_others_category_is_optional(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);
        $this->uploadDocument($this->others);
        $this->uploadDocument($this->bankStatements);

        $this->assertSame('upload_documents', $this->onboarding()['current_step']);

        $this->uploadDocument($this->einLetter);

        $this->assertSame('results_ready', $this->onboarding()['current_step']);
    }

    #[Test]
    public function a_category_whose_only_document_was_rejected_still_needs_one(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);
        $this->uploadDocument($this->bankStatements);
        $this->uploadDocument($this->einLetter, BorrowerDocument::STATUS_REJECTED);

        $this->assertSame('upload_documents', $this->onboarding()['current_step']);
    }

    #[Test]
    public function documents_in_every_required_category_bring_the_borrower_to_their_results(): void
    {
        // Nothing to wait for: the results show once the borrower's part is done.
        $this->startJourney();
        $this->submitCreditProfile();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);
        $this->uploadAllDocuments();

        $onboarding = $this->onboarding();
        $this->assertSame('results_ready', $onboarding['current_step']);
        $this->assertSame(4, $onboarding['completed_steps']);
        $this->assertTrue($onboarding['results_ready']);
    }

    #[Test]
    public function a_rejected_document_does_not_count(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);
        $this->uploadDocument($this->bankStatements, BorrowerDocument::STATUS_REJECTED);
        $this->uploadDocument($this->einLetter, BorrowerDocument::STATUS_REJECTED);

        $this->assertSame('upload_documents', $this->onboarding()['current_step']);
    }

    #[Test]
    public function the_documents_step_cannot_be_skipped(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);

        $this->postJson('/api/v1/borrower/profile/onboarding/skip', ['step' => 'upload_documents'])->assertStatus(422);
        $this->assertSame('upload_documents', $this->onboarding()['current_step']);
    }

    #[Test]
    public function verify_all_waits_until_every_tier_0_task_is_submitted(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING);

        $this->assertSame('verify_all', $this->onboarding()['current_step']);
    }

    #[Test]
    public function a_rejected_tier_0_task_sends_the_borrower_back_to_resubmit(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::COMPLETED, BorrowerTaskVerificationRequest::REJECTED);

        $this->assertSame('verify_all', $this->onboarding()['current_step']);
    }

    #[Test]
    public function with_every_tier_0_task_submitted_and_documents_in_the_results_are_ready(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::COMPLETED);

        $onboarding = $this->onboarding();
        $this->assertSame('results_ready', $onboarding['current_step']);
        $this->assertTrue($onboarding['results_ready']);
    }

    #[Test]
    public function the_results_do_not_wait_for_the_credit_profile_to_be_verified(): void
    {
        // Submitted, with no iSoftPull report and not verified yet.
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);

        $this->assertTrue($this->onboarding()['results_ready']);
    }

    #[Test]
    public function the_results_do_not_wait_for_tier_0_to_be_verified(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);
        $this->borrower->update(['credit_profile_updated' => true]);

        $onboarding = $this->onboarding();
        $this->assertSame('results_ready', $onboarding['current_step']);
        $this->assertTrue($onboarding['results_ready']);
    }

    #[Test]
    public function a_profile_verified_without_a_request_is_not_sent_back_to_the_form(): void
    {
        // An admin can attach a credit profile directly.
        $this->startJourney();
        $this->borrower->update(['credit_profile_updated' => true]);

        // On to the step after the credit profile, not back to its form.
        $this->assertSame('verify_all', $this->onboarding()['current_step']);
    }

    #[Test]
    public function the_borrowers_part_done_makes_the_results_ready(): void
    {
        $this->resultsReady();

        $onboarding = $this->onboarding();
        $this->assertSame('results_ready', $onboarding['current_step']);
        $this->assertSame(4, $onboarding['completed_steps']);
        $this->assertTrue($onboarding['results_ready']);
    }

    // -- reaching Credit Card Stacking ---------------------------------------

    #[Test]
    public function showing_the_results_once_they_are_ready_completes_the_journey(): void
    {
        $this->resultsReady();

        $this->postJson('/api/v1/borrower/profile/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('data.current_step', 'completed');

        $onboarding = $this->onboarding();
        $this->assertSame('completed', $onboarding['current_step']);
        $this->assertSame($onboarding['total_steps'], $onboarding['completed_steps']);
        $this->assertNotNull($this->progress()->completed_at);
    }

    #[Test]
    public function the_journey_cannot_be_completed_before_the_results_are_ready(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();

        $this->postJson('/api/v1/borrower/profile/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('data.current_step', 'verify_all');

        $this->assertNull($this->progress()?->completed_at);
    }

    #[Test]
    public function the_journey_completes_while_tier_0_is_still_being_verified(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);

        $this->postJson('/api/v1/borrower/profile/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('data.current_step', 'completed');

        $this->assertNotNull($this->progress()->completed_at);
    }

    #[Test]
    public function completing_twice_keeps_the_first_time(): void
    {
        $this->resultsReady();

        $this->postJson('/api/v1/borrower/profile/onboarding/complete')->assertOk();
        $first = $this->progress()->completed_at;
        $this->travel(5)->minutes();
        $this->postJson('/api/v1/borrower/profile/onboarding/complete')->assertOk();

        $this->assertEquals($first, $this->progress()->completed_at);
    }

    // -- choosing a path once the results are ready ---------------------------
    //
    // With the results ready the borrower chooses what they are looking for:
    // card stacking, a single card, loans and other financing, or a call with
    // an advisor. The choice is kept so the portal can point them to it on
    // every login, and it can be changed.

    private function choosePath(string $path)
    {
        return $this->postJson('/api/v1/borrower/profile/onboarding/path', ['path' => $path]);
    }

    #[Test]
    public function results_ready_starts_with_no_path_chosen(): void
    {
        $this->resultsReady();

        $this->assertNull($this->onboarding()['selected_path']);
    }

    #[Test]
    public function the_borrower_chooses_a_path_once_the_results_are_ready(): void
    {
        $this->resultsReady();

        $this->choosePath('funding')
            ->assertOk()
            ->assertJsonPath('data.current_step', 'results_ready')
            ->assertJsonPath('data.selected_path', 'funding');

        $this->assertSame('funding', $this->onboarding()['selected_path']);
        $this->assertSame('funding', $this->progress()->selected_path);
        $this->assertNotNull($this->progress()->path_selected_at);
    }

    #[Test]
    public function every_path_on_offer_can_be_chosen(): void
    {
        $this->resultsReady();

        foreach (OnboardingStepService::PATHS as $path) {
            $this->choosePath($path)->assertOk()->assertJsonPath('data.selected_path', $path);
        }
    }

    #[Test]
    public function the_choice_can_be_changed(): void
    {
        $this->resultsReady();
        $this->choosePath('card_stacking')->assertOk();
        $first = $this->progress()->path_selected_at;

        $this->travel(5)->minutes();
        $this->choosePath('advisor')->assertOk()->assertJsonPath('data.selected_path', 'advisor');

        $this->assertSame('advisor', $this->progress()->selected_path);
        $this->assertTrue($this->progress()->path_selected_at->greaterThan($first));
    }

    #[Test]
    public function a_path_cannot_be_chosen_before_the_results_are_ready(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();

        $this->choosePath('funding')->assertStatus(422)->assertJsonValidationErrors('path');

        $this->assertNull($this->progress()?->selected_path);
    }

    #[Test]
    public function a_path_can_be_chosen_while_tier_0_is_still_being_verified(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);

        $this->choosePath('funding')->assertOk()->assertJsonPath('data.selected_path', 'funding');
    }

    #[Test]
    public function an_unknown_path_cannot_be_chosen(): void
    {
        $this->resultsReady();

        $this->choosePath('lottery')->assertStatus(422)->assertJsonValidationErrors('path');
    }

    #[Test]
    public function the_chosen_path_is_still_reported_once_the_journey_is_complete(): void
    {
        $this->resultsReady();
        $this->choosePath('single_card')->assertOk();

        $this->postJson('/api/v1/borrower/profile/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('data.current_step', 'completed')
            ->assertJsonPath('data.selected_path', 'single_card');
    }

    // -- no skipping ----------------------------------------------------------
    //
    // Every paid borrower has to get through Tier 0: no step can be skipped,
    // and a skip saved before that changed no longer counts.

    private function skip(string $step)
    {
        return $this->postJson('/api/v1/borrower/profile/onboarding/skip', ['step' => $step]);
    }

    private function resume(string $step)
    {
        return $this->postJson('/api/v1/borrower/profile/onboarding/resume', ['step' => $step]);
    }

    /** A skip saved back when Verify All and the review could still be skipped. */
    private function skippedEarlier(string ...$steps): void
    {
        $this->onboarding();
        $progress = $this->progress();
        $progress->skipped_steps = $steps;
        $progress->save();
    }

    #[Test]
    public function verify_all_cannot_be_skipped(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();

        $this->skip('verify_all')->assertStatus(422);

        $this->assertSame('verify_all', $this->onboarding()['current_step']);
        $this->assertEmpty($this->progress()->skipped_steps ?? []);
    }

    #[Test]
    public function there_is_no_review_step_to_skip(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);

        $this->skip('in_review')->assertStatus(422);

        $this->assertSame('results_ready', $this->onboarding()['current_step']);
    }

    #[Test]
    public function the_credit_profile_step_cannot_be_skipped(): void
    {
        $this->startJourney();

        $this->skip('credit_profile')->assertStatus(422);

        $this->assertSame('credit_profile', $this->onboarding()['current_step']);
    }

    #[Test]
    public function an_unknown_step_cannot_be_skipped(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();

        $this->skip('completed')->assertStatus(422);
        $this->postJson('/api/v1/borrower/profile/onboarding/skip')->assertStatus(422);
    }

    #[Test]
    public function an_earlier_skip_of_verify_all_no_longer_lets_the_borrower_past_tier_0(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->skippedEarlier('verify_all');

        $onboarding = $this->onboarding();
        $this->assertSame('verify_all', $onboarding['current_step']);
        $this->assertSame([], $onboarding['pending_steps']);
    }

    #[Test]
    public function an_earlier_skip_of_the_old_review_step_is_ignored(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);
        $this->skippedEarlier('in_review');

        $onboarding = $this->onboarding();
        $this->assertSame('results_ready', $onboarding['current_step']);
        $this->assertTrue($onboarding['results_ready']);
        $this->assertSame([], $onboarding['pending_steps']);
    }

    #[Test]
    public function the_journey_cannot_finish_without_tier_0_even_after_an_earlier_skip(): void
    {
        // Credit Card Stacking has opened (credit profile updated), but Tier 0
        // was skipped back then and never submitted.
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->skippedEarlier('verify_all');
        $this->borrower->update(['credit_profile_updated' => true]);

        $this->postJson('/api/v1/borrower/profile/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('data.current_step', 'verify_all');

        $this->assertNull($this->progress()->completed_at);
    }

    #[Test]
    public function there_is_no_skipped_step_to_go_back_to(): void
    {
        $this->startJourney();
        $this->submitCreditProfile();
        $this->uploadAllDocuments();
        $this->skippedEarlier('verify_all');

        $this->resume('verify_all')->assertStatus(422);
        $this->resume('credit_profile')->assertStatus(422);
        $this->postJson('/api/v1/borrower/profile/onboarding/resume')->assertStatus(422);

        $this->assertSame('verify_all', $this->onboarding()['current_step']);
    }

    // -- free-trial borrowers ------------------------------------------------
    //
    // The walkthrough proper is for paid borrowers. On the free trial it is
    // only the profile and the credit profile: no documents, no Tier 0, no
    // results to choose from, nothing to skip.

    private function startFreeTrial(): void
    {
        $this->borrower->update(['is_free_trail' => true]);
        $this->completeProfile();
    }

    private function freeTrialProgress(): ?WalkthroughProgress
    {
        return WalkthroughProgress::where('entity_type', Borrower::class)
            ->where('entity_id', $this->borrower->id)
            ->where('walkthrough', WalkthroughProgress::BORROWER_FREE_TRIAL)
            ->first();
    }

    #[Test]
    public function a_free_trial_walkthrough_ends_with_the_credit_profile(): void
    {
        $this->startFreeTrial();
        $this->submitCreditProfile();

        $onboarding = $this->onboarding();
        $this->assertSame('completed', $onboarding['current_step']);
        $this->assertSame(2, $onboarding['completed_steps']);
        $this->assertSame(2, $onboarding['total_steps']);
        // Kept apart from the paid walkthrough.
        $this->assertSame('completed', $this->freeTrialProgress()->current_step);
        $this->assertNull($this->progress());
    }

    #[Test]
    public function a_free_trial_borrower_is_never_asked_for_documents_or_tier_0(): void
    {
        $this->startFreeTrial();
        $this->submitCreditProfile();
        $this->tier0Requests(BorrowerTaskVerificationRequest::PROCESSING, BorrowerTaskVerificationRequest::PROCESSING);

        $onboarding = $this->onboarding();
        $this->assertSame('completed', $onboarding['current_step']);
        $this->assertSame([], $onboarding['pending_steps']);
    }

    #[Test]
    public function a_free_trial_borrower_does_not_need_a_paid_subscription(): void
    {
        $this->subscribe(Subscription::STATUS_PENDING);
        $this->startFreeTrial();

        $this->assertSame('credit_profile', $this->onboarding()['current_step']);
    }

    #[Test]
    public function the_free_trial_steps_cannot_be_skipped(): void
    {
        $this->startFreeTrial();

        $this->postJson('/api/v1/borrower/profile/onboarding/skip', ['step' => 'credit_profile'])->assertStatus(422);
    }

    #[Test]
    public function a_free_trial_borrower_has_no_path_to_choose(): void
    {
        $this->startFreeTrial();
        $this->submitCreditProfile();
        $this->borrower->update(['credit_profile_updated' => true]);

        $this->postJson('/api/v1/borrower/profile/onboarding/path', ['path' => 'funding'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('path');
    }

    #[Test]
    public function moving_to_a_paid_plan_starts_the_paid_walkthrough(): void
    {
        $this->startFreeTrial();
        $this->submitCreditProfile();
        $this->onboarding();

        $this->borrower->update(['is_free_trail' => false]);
        $this->subscribe();

        $onboarding = $this->onboarding();
        $this->assertSame('paid', $onboarding['flow']);
        // The credit profile carries over; Verify All is the paid journey's next step.
        $this->assertSame('verify_all', $onboarding['current_step']);
    }

    // -- the shape and the column -------------------------------------------

    #[Test]
    public function it_reports_how_far_through_the_journey_the_borrower_is(): void
    {
        $this->subscribe();
        $this->completeProfile();
        $this->submitCreditProfile();

        $this->assertSame([
            'flow' => 'paid',
            'current_step' => 'verify_all',
            'completed_steps' => 2,
            'total_steps' => 5,
            'results_ready' => false,
            'pending_steps' => [],
            'selected_path' => null,
        ], $this->onboarding());
    }

    #[Test]
    public function a_journey_not_yet_started_reports_no_progress(): void
    {
        $this->subscribe(Subscription::STATUS_PENDING);

        $this->assertSame([
            'flow' => 'paid',
            'current_step' => null,
            'completed_steps' => 0,
            'total_steps' => 5,
            'results_ready' => false,
            'pending_steps' => [],
            'selected_path' => null,
        ], $this->onboarding());
    }

    #[Test]
    public function the_current_step_is_kept_in_the_walkthrough_table(): void
    {
        $this->subscribe(Subscription::STATUS_PENDING);
        $this->onboarding();
        // Nothing to keep before the journey starts (not paid).
        $this->assertNull($this->progress());

        Subscription::query()->update(['status' => Subscription::STATUS_ACTIVE]);
        $this->onboarding();
        $this->assertSame('complete_profile', $this->progress()->current_step);

        $this->completeProfile();
        $this->onboarding();
        $this->assertDatabaseHas('walkthrough_progress', [
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'walkthrough' => 'borrower_onboarding',
            'current_step' => 'credit_profile',
        ]);

        $this->submitCreditProfile();
        $this->onboarding();
        $this->assertSame('verify_all', $this->progress()->current_step);
        // Still one row per borrower per walkthrough.
        $this->assertSame(1, WalkthroughProgress::count());
    }

    #[Test]
    public function the_borrowers_table_is_not_used(): void
    {
        $this->startJourney();
        $this->onboarding();

        foreach (['onboarding_step', 'onboarding_completed_at', 'onboarding_skipped_steps'] as $column) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('borrowers', $column), $column);
        }
    }

    #[Test]
    public function another_account_with_the_same_id_has_its_own_progress(): void
    {
        // A partner's walkthrough will share the table: rows are told apart by
        // entity type, not just id.
        WalkthroughProgress::create([
            'entity_type' => Partner::class,
            'entity_id' => $this->borrower->id,
            'walkthrough' => WalkthroughProgress::BORROWER_ONBOARDING,
            'completed_at' => now(),
        ]);
        $this->startJourney();

        $this->assertSame('credit_profile', $this->onboarding()['current_step']);
    }

    #[Test]
    public function the_existing_completion_fields_are_unchanged(): void
    {
        $this->subscribe();

        $this->getJson('/api/v1/borrower/profile/completion')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'is_complete',
                'personal' => ['is_complete', 'missing_fields'],
                'business' => ['is_complete', 'missing_fields'],
                'financial' => ['is_complete', 'missing_fields'],
                'onboarding',
            ]]);
    }
}
