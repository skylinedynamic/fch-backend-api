<?php

namespace Tests\Feature\PreQualification;

use App\Http\Controllers\Api\V1\Borrower\PreQualification\PreQualificationController;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\User\User;
use App\Services\FCH\Common\Application\PreQualificationAiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower portal's pre-qualification wizard (/borrower/pre-qualification/*):
 * each step saves onto the borrower's one in-progress (On Hold) draft, submit
 * hands it to underwriting, and a borrower only ever reaches their own
 * requests. The AI underwriting itself is replaced here; it has its own suite.
 */
#[CoversClass(PreQualificationController::class)]
class BorrowerPreQualificationWizardApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
    }

    private function personal(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Bo',
            'last_name' => 'Rrower',
            'email' => 'bo@example.org',
            'phone_number' => '5552340100',
            'state_of_incorporation' => 'Texas',
        ], $overrides);
    }

    private function business(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Bo Co',
            'industry_type' => 'Retail',
            'business_structure' => 'LLC',
            'monthly_revenue' => 10000,
            'annual_revenue' => 120000,
            'years_in_business' => '2-5',
        ], $overrides);
    }

    private function draftFor(Borrower $borrower, string $status = PreQualificationRequest::ON_HOLD, array $attributes = []): PreQualificationRequest
    {
        return PreQualificationRequest::create(array_merge([
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'status' => $status,
            'first_name' => 'Someone',
        ], $attributes));
    }

    private function myBorrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    #[Test]
    public function the_steps_fill_one_draft(): void
    {
        $this->actingAsUser($this->me);

        $id = $this->postJson('/api/v1/borrower/pre-qualification/personal-info', $this->personal())->assertOk()->json('data.id');
        $this->postJson('/api/v1/borrower/pre-qualification/business-info', $this->business())->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/borrower/pre-qualification/business-info', $this->business(['id' => $id, 'business_name' => 'Bo Co 2']))
            ->assertOk();

        $this->assertSame(1, PreQualificationRequest::where('borrower_id', $this->me->entity_id)->count());
        $draft = PreQualificationRequest::find($id);
        $this->assertSame(PreQualificationRequest::ON_HOLD, $draft->status);
        $this->assertSame('Bo Co 2', $draft->business_name);
        $this->assertSame($this->partner->id, (int) $draft->partner_id);

        $this->getJson('/api/v1/borrower/pre-qualification')->assertOk()->assertJsonPath('data.id', $id);
    }

    /**
     * Defect (BUG-167, CP-PREQ-10): the Funding Needs step was saved ("funding
     * information updated successfully") but GET blanked those fields on
     * purpose, so a client who left and came back found the step empty again.
     * The draft resumes with what was saved in every step, Funding Needs too.
     */
    #[Test]
    public function the_draft_resumes_with_its_saved_funding_needs(): void
    {
        $this->actingAsUser($this->me);
        $id = $this->postJson('/api/v1/borrower/pre-qualification/personal-info', $this->personal())->assertOk()->json('data.id');

        $this->postJson('/api/v1/borrower/pre-qualification/funding-info', [
            'id' => $id,
            'funding_requested_amount' => 16000,
            'funding_urgency' => 'Within 30 days',
            'purpose_of_funding' => 'Business Expansion',
            'personal_credit_score' => 'Good (670-739)',
            'estimated_business_credit_score' => 'Medium',
            'existing_business_debt' => true,
            'debt_balance' => 5000,
            'notes' => 'Peak season stock',
        ])->assertOk();

        $this->getJson('/api/v1/borrower/pre-qualification')
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.funding_requested_amount', currencyFormat(16000))
            ->assertJsonPath('data.funding_urgency', 'Within 30 days')
            ->assertJsonPath('data.purpose_of_funding', 'Business Expansion')
            ->assertJsonPath('data.personal_credit_score', 'Good (670-739)')
            ->assertJsonPath('data.estimated_business_credit_score', 'Medium')
            ->assertJsonPath('data.existing_business_debt', true)
            ->assertJsonPath('data.debt_balance', currencyFormat(5000))
            ->assertJsonPath('data.notes', 'Peak season stock');
    }

    #[Test]
    public function opening_the_wizard_does_not_create_a_draft(): void
    {
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/pre-qualification')->assertOk()->assertJsonPath('data.id', null);
        $this->assertSame(0, PreQualificationRequest::count());
    }

    #[Test]
    public function a_step_is_validated(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/pre-qualification/personal-info', $this->personal(['email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/borrower/pre-qualification/business-info', $this->business(['monthly_revenue' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('monthly_revenue');
    }

    /** BUG-017: step 1 with no first name, a bad email and no state reports all three at once. */
    #[Test]
    public function personal_info_reports_every_error_in_one_response(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/pre-qualification/personal-info', $this->personal(['first_name' => '', 'email' => 'bad@', 'state_of_incorporation' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'email', 'state_of_incorporation']);
    }

    /**
     * Defect (BUG-037, CP-PREQ-04): a borrower with no business start date in
     * their profile was stuck on Business Details - Time in Business was a
     * read-only "Please set business start date in profile" and Next failed
     * with "Years in business is required". The step now takes the start date,
     * saves it to the profile and derives Time in Business from it.
     */
    #[Test]
    public function a_borrower_without_a_business_start_date_sets_it_on_the_business_step(): void
    {
        $this->assertNull($this->myBorrower()->business_start_date);
        $startDate = now()->subYears(3)->subMonths(2)->toDateString();
        $this->actingAsUser($this->me);

        $id = $this->postJson('/api/v1/borrower/pre-qualification/business-info', $this->business([
            'years_in_business' => null,
            'business_start_date' => $startDate,
        ]))->assertOk()->json('data.id');

        $this->assertSame($startDate, Carbon::parse($this->myBorrower()->business_start_date)->toDateString());
        $draft = PreQualificationRequest::find($id);
        $this->assertSame($startDate, $draft->business_start_date->toDateString());
        $this->assertSame(calculateTimeInBusiness($startDate), $draft->years_in_business);
        $this->getJson('/api/v1/borrower/pre-qualification')
            ->assertOk()
            ->assertJsonPath('data.years_in_business', calculateTimeInBusiness($startDate))
            ->assertJsonPath('data.business_start_date', $startDate);
    }

    /**
     * Once the profile has a start date, changing it goes through a profile
     * change request - the wizard must not become a way around that.
     */
    #[Test]
    public function the_business_step_never_overwrites_a_saved_business_start_date(): void
    {
        $this->myBorrower()->update(['business_start_date' => '2015-01-01']);
        $this->actingAsUser($this->me);

        $id = $this->postJson('/api/v1/borrower/pre-qualification/business-info', $this->business([
            'years_in_business' => '1 Year',
            'business_start_date' => '2024-01-01',
        ]))->assertOk()->json('data.id');

        $this->assertSame('2015-01-01', Carbon::parse($this->myBorrower()->business_start_date)->toDateString());
        $draft = PreQualificationRequest::find($id);
        $this->assertSame('2015-01-01', $draft->business_start_date->toDateString());
        $this->assertSame(calculateTimeInBusiness('2015-01-01'), $draft->years_in_business);
    }

    #[Test]
    public function the_business_step_needs_a_start_date_or_time_in_business(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/pre-qualification/business-info', $this->business(['years_in_business' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('years_in_business');
        $this->postJson('/api/v1/borrower/pre-qualification/business-info', $this->business([
            'years_in_business' => null,
            'business_start_date' => now()->addDay()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('business_start_date');
        $this->assertNull($this->myBorrower()->business_start_date);
    }

    #[Test]
    public function another_borrowers_draft_cannot_be_written_or_submitted(): void
    {
        $theirs = $this->draftFor($this->makeBorrower($this->partner));
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/pre-qualification/personal-info', $this->personal(['id' => $theirs->id]))->assertNotFound();
        $this->postJson('/api/v1/borrower/pre-qualification/submit', ['id' => $theirs->id])->assertNotFound();

        $theirs->refresh();
        $this->assertSame('Someone', $theirs->first_name);
        $this->assertSame(PreQualificationRequest::ON_HOLD, $theirs->status);
    }

    #[Test]
    public function submitting_hands_the_draft_to_underwriting_once(): void
    {
        $draft = $this->draftFor($this->myBorrower());
        $this->mock(PreQualificationAiService::class)->shouldReceive('evaluate')->atLeast()->once();
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/pre-qualification/submit', ['id' => $draft->id])
            ->assertOk()
            ->assertJsonPath('data.gpt_status', PreQualificationRequest::GPT_PENDING);
        $this->assertSame(PreQualificationRequest::PENDING, $draft->fresh()->status);

        // No longer On Hold, so a second submit finds nothing to submit.
        $this->postJson('/api/v1/borrower/pre-qualification/submit', ['id' => $draft->id])->assertNotFound();
    }

    /**
     * Defect (BUG-168, CP-PREQ-13): three submits of one draft at the same
     * instant were all accepted (200) and each started its own underwriting
     * run. Each submit read the draft as On Hold before any of them had saved
     * it as Pending. Here a second submit claims the draft right after this
     * one has read it: this one must then be refused, not run underwriting
     * again.
     */
    #[Test]
    public function a_draft_another_submit_claimed_meanwhile_is_not_submitted_again(): void
    {
        $draft = $this->draftFor($this->myBorrower());
        $this->mock(PreQualificationAiService::class)->shouldNotReceive('evaluate');
        $this->actingAsUser($this->me);

        PreQualificationRequest::retrieved(function (PreQualificationRequest $read) use ($draft) {
            if ($read->id === $draft->id) {
                PreQualificationRequest::whereKey($draft->id)->update(['status' => PreQualificationRequest::PENDING]);
            }
        });

        $this->postJson('/api/v1/borrower/pre-qualification/submit', ['id' => $draft->id])->assertNotFound();
    }

    #[Test]
    public function a_failed_underwriting_is_recorded_and_can_be_retried(): void
    {
        $draft = $this->draftFor($this->myBorrower());
        $this->mock(PreQualificationAiService::class)
            ->shouldReceive('evaluate')
            ->andThrow(new \RuntimeException('OpenAI is down'));
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/pre-qualification/submit', ['id' => $draft->id])->assertOk();
        $this->assertSame(PreQualificationRequest::GPT_FAILED, $draft->fresh()->gpt_status);

        // Answered as under review again; the (still failing) run that follows
        // the response marks it Failed once more.
        $this->postJson('/api/v1/borrower/pre-qualification/retry-underwriting', ['id' => $draft->id])
            ->assertOk()
            ->assertJsonPath('data.gpt_status', PreQualificationRequest::GPT_PENDING);
        $this->assertSame(PreQualificationRequest::GPT_FAILED, $draft->fresh()->gpt_status);
    }

    #[Test]
    public function only_a_failed_underwriting_can_be_retried(): void
    {
        $pending = $this->draftFor($this->myBorrower(), PreQualificationRequest::PENDING, ['gpt_status' => PreQualificationRequest::GPT_PENDING]);
        $theirs = $this->draftFor($this->makeBorrower($this->partner), PreQualificationRequest::PENDING, ['gpt_status' => PreQualificationRequest::GPT_FAILED]);
        $this->mock(PreQualificationAiService::class)->shouldNotReceive('evaluate');
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/pre-qualification/retry-underwriting', ['id' => $pending->id])->assertNotFound();
        $this->postJson('/api/v1/borrower/pre-qualification/retry-underwriting', ['id' => $theirs->id])->assertNotFound();
    }

    #[Test]
    public function a_borrower_opens_only_their_own_submitted_requests(): void
    {
        $mine = $this->draftFor($this->myBorrower(), PreQualificationRequest::PENDING);
        $myDraft = $this->draftFor($this->myBorrower());
        $theirs = $this->draftFor($this->makeBorrower($this->partner), PreQualificationRequest::PENDING);
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/pre-qualification/{$mine->id}")->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->getJson("/api/v1/borrower/pre-qualification/{$myDraft->id}")->assertNotFound();
        $this->getJson("/api/v1/borrower/pre-qualification/{$theirs->id}")->assertNotFound();
    }
}
