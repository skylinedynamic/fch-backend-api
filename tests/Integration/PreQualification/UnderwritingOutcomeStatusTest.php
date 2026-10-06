<?php

namespace Tests\Integration\PreQualification;

use App\Http\Resources\Borrower\PreQualification\PreQualificationResource;
use App\Models\Borrower\PreQualificationRequest;
use App\Services\FCH\Common\Application\PreQualificationAiService;
use App\Services\FCH\Common\PreQualification\AUE\PreQualificationInternalAueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The application's workflow status after the automated review.
 *
 * Failed when the agent called the borrower's information incorrect or the
 * evaluation could not be produced; Pending when it completed. A status a
 * reviewer has already moved the file to is theirs, and a re-run leaves it.
 */
class UnderwritingOutcomeStatusTest extends TestCase
{
    use RefreshDatabase;

    private const COMPLETE = [
        'data_status' => 'COMPLETE',
        'overall_stance' => 'CONDITIONAL_REVIEW',
        'strengths' => ['Monthly revenue is consistent'],
        'conditions_and_flags' => [],
    ];

    private const CONFLICTING = [
        'data_status' => 'CONFLICTING',
        'overall_stance' => 'INSUFFICIENT_DATA',
        'conditions_and_flags' => [[
            'category' => 'Revenue Reconciliation',
            'severity' => 'BLOCKING',
            'finding' => 'Monthly and annual revenue do not agree.',
            'required_action' => 'Please check both figures.',
        ]],
    ];

    private function application(string $status = PreQualificationRequest::PENDING): PreQualificationRequest
    {
        return PreQualificationRequest::create([
            'business_name' => 'Alpha Tech',
            'purpose_of_funding' => 'Working Capital',
            'funding_requested_amount' => 50000,
            'status' => $status,
            'gpt_status' => PreQualificationRequest::GPT_PENDING,
        ]);
    }

    /** Runs one evaluation with the agent answering $data, or failing when null. */
    private function evaluate(PreQualificationRequest $application, ?array $data): PreQualificationRequest
    {
        $this->mock(PreQualificationInternalAueService::class, function ($mock) use ($data) {
            $mock->shouldReceive('evaluate')->once()->andReturn([
                'ok' => $data !== null,
                'data' => $data,
                'is_fallback' => false,
                'model' => $data !== null ? 'gpt-test' : null,
                'latency_ms' => 1,
                'error' => $data !== null ? null : 'OpenAI could not be reached.',
            ]);
        });

        app(PreQualificationAiService::class)->evaluate($application->fresh());

        return $application->fresh();
    }

    #[Test]
    public function a_completed_review_leaves_the_application_pending(): void
    {
        $after = $this->evaluate($this->application(), self::COMPLETE);

        $this->assertSame(PreQualificationRequest::PENDING, $after->status);
        $this->assertSame(PreQualificationRequest::GPT_COMPLETED, $after->gpt_status);
    }

    #[Test]
    public function information_the_agent_calls_incorrect_marks_the_application_failed(): void
    {
        $after = $this->evaluate($this->application(), self::CONFLICTING);

        $this->assertSame(PreQualificationRequest::FAILED, $after->status);
        $this->assertSame(PreQualificationRequest::VALIDATION_INCORRECT, $after->validation_status);
    }

    #[Test]
    public function an_evaluation_that_could_not_be_produced_marks_the_application_failed(): void
    {
        $after = $this->evaluate($this->application(), null);

        $this->assertSame(PreQualificationRequest::FAILED, $after->status);
        $this->assertSame(PreQualificationRequest::GPT_FAILED, $after->gpt_status);
    }

    #[Test]
    public function a_failed_application_returns_to_pending_once_a_re_run_completes(): void
    {
        $after = $this->evaluate($this->application(PreQualificationRequest::FAILED), self::COMPLETE);

        $this->assertSame(PreQualificationRequest::PENDING, $after->status);
    }

    public static function reviewerStatuses(): array
    {
        return [
            'intake received' => [PreQualificationRequest::INTAKE_RECEIVED],
            'in review' => [PreQualificationRequest::IN_REVIEW],
            'approved' => [PreQualificationRequest::APPROVED],
            'declined' => [PreQualificationRequest::DECLINED],
        ];
    }

    #[Test]
    #[DataProvider('reviewerStatuses')]
    public function a_re_run_never_undoes_a_status_a_reviewer_set(string $status): void
    {
        $this->assertSame($status, $this->evaluate($this->application($status), self::COMPLETE)->status);
        $this->assertSame($status, $this->evaluate($this->application($status), self::CONFLICTING)->status);
        $this->assertSame($status, $this->evaluate($this->application($status), null)->status);
    }

    #[Test]
    public function failed_is_a_status_a_reviewer_can_choose(): void
    {
        $this->assertContains(PreQualificationRequest::FAILED, PreQualificationRequest::STATUS);
        $this->assertSame('danger', PreQualificationRequest::STATUS_CLASS[PreQualificationRequest::FAILED]);
    }

    #[Test]
    public function a_re_run_the_agent_blocks_does_not_leave_the_previous_result_in_front_of_the_borrower(): void
    {
        // A first run that completed and released a borrower result.
        $application = $this->evaluate($this->application(), self::COMPLETE);
        $shown = fn () => (new PreQualificationResource($application->fresh()))->toArray(request());
        $this->assertNotNull($shown()['underwriting_result']);

        // The re-run: this time the agent calls the borrower's figures conflicting.
        $this->evaluate($application, self::CONFLICTING);

        // The borrower is told what to correct, and the earlier run's
        // assessment — built on figures since called unreliable — is gone.
        $after = $shown();
        $this->assertNotEmpty($after['validation_corrections']);
        $this->assertNull($after['underwriting_result']);
    }
}
