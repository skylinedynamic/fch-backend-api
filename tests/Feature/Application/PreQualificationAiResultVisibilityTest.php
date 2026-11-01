<?php

namespace Tests\Feature\Application;

use App\Http\Resources\FCH\Common\Application\PreQualificationRequestResource;
use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Intake & Review > Pre-Qualification > Details > AI Underwriting Result.
 * The host sees both evaluations; every partner (white-label and certified
 * alike) sees the Client Readiness evaluation only — never the internal memo,
 * lender routing, model or admin note.
 */
#[CoversClass(PreQualificationRequestResource::class)]
class PreQualificationAiResultVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function evaluatedPreQualFor(Partner $partner): int
    {
        $borrower = $this->makeBorrower($partner);

        $id = DB::table('pre_qualification_requests')->insertGetId([
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'status' => PreQualificationRequest::PENDING,
            'gpt_status' => PreQualificationRequest::GPT_COMPLETED,
            'gpt_generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            PreQualificationGptResult::TYPE_INTERNAL => ['overall_stance' => 'CONDITIONAL REVIEW'],
            PreQualificationGptResult::TYPE_CLIENT => ['assessment_title' => 'Funding Readiness'],
        ] as $type => $payload) {
            DB::table('pre_qualification_gpt_results')->insert([
                'pre_qualification_request_id' => $id,
                'type' => $type,
                'prompt_version' => 'test',
                'model' => 'test-model',
                'payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function details(int $id)
    {
        return $this->getJson("/api/v1/common/request-application/pre-qualification/{$id}")->assertOk();
    }

    /**
     * Defect: a certified partner's details page showed no AI Underwriting
     * Result at all — ai_result and gpt_status were nulled for every partner
     * that was not white-label — while a white-label partner saw the client view.
     */
    #[Test]
    public function a_certified_partner_sees_the_client_evaluation_but_not_the_internal_one(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->makePartnerAdmin($partner));

        $response = $this->details($this->evaluatedPreQualFor($partner));

        $response->assertJsonPath('data.gpt_status', PreQualificationRequest::GPT_COMPLETED)
            ->assertJsonPath('data.ai_result.client.assessment_title', 'Funding Readiness')
            ->assertJsonMissingPath('data.ai_result.internal')
            ->assertJsonMissingPath('data.ai_result.admin_status_note')
            ->assertJsonMissingPath('data.ai_result.model');
    }

    #[Test]
    public function a_white_label_partner_sees_the_client_evaluation_but_not_the_internal_one(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $this->actingAsUser($this->makePartnerAdmin($partner));

        $response = $this->details($this->evaluatedPreQualFor($partner));

        $response->assertJsonPath('data.gpt_status', PreQualificationRequest::GPT_COMPLETED)
            ->assertJsonPath('data.ai_result.client.assessment_title', 'Funding Readiness')
            ->assertJsonMissingPath('data.ai_result.internal')
            ->assertJsonMissingPath('data.ai_result.model');
    }

    #[Test]
    public function the_host_sees_both_evaluations(): void
    {
        $partner = $this->makeCertifiedPartner();
        $id = $this->evaluatedPreQualFor($partner);
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));

        $this->details($id)
            ->assertJsonPath('data.ai_result.internal.overall_stance', 'CONDITIONAL REVIEW')
            ->assertJsonPath('data.ai_result.client.assessment_title', 'Funding Readiness');
    }
}
