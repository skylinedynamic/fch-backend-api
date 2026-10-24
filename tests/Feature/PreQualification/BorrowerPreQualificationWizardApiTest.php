<?php

namespace Tests\Feature\PreQualification;

use App\Http\Controllers\Api\V1\Borrower\PreQualification\PreQualificationController;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\User\User;
use App\Services\FCH\Common\Application\PreQualificationAiService;
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
