<?php

namespace Tests\Integration\PreQualification;

use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Common\AppConfiguration;
use App\Models\Lender\Lender;
use App\Models\PreQualification\PreQualificationAgentConfig;
use App\Services\FCH\Common\PreQualification\AUE\PreQualificationClientAueService;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A one-agent setup: the internal evaluation is the only agent configured.
 *
 * The borrower still gets their own result, and it is still a separate row
 * from the internal memo — it is the borrower-safe part of the internal
 * agent's own output rather than a second agent's rewording of it. Nothing is
 * written by this codebase, and the boundary between the two audiences is the
 * same one a two-agent setup is held to.
 */
class OneAgentPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** What the internal agent returned, in its own schema. */
    private const INTERNAL_RESULT = [
        'prompt_version' => 'FCH_Internal_Prequalification_AUE_v3',
        'data_status' => 'COMPLETE',
        'overall_stance' => 'CONDITIONAL_REVIEW',

        // Borrower-safe once the lender names are gone.
        'strengths' => ['Twenty-six months in business', 'Monthly revenue is consistent'],
        'credit_building_pathway' => ['Open a net-30 vendor account'],
        'conditions_and_flags' => [[
            'category' => 'Personal Credit',
            'severity' => 'MATERIAL',
            'finding' => 'The selected credit band sits below the preliminary threshold.',
            'required_action' => 'Provide an updated credit report if you choose to proceed.',
            'evidence_fields' => ['personal_credit_band'],
        ]],
        'recommended_funding_paths' => [[
            'priority' => 1,
            'product_category' => 'Working Capital',
            'aus_model' => 'MCA_AUS',
            'fit_status' => 'POTENTIAL_FIT',
            'fit_rationale' => 'Revenue supports a preliminary revenue-based path.',
            'amount_min' => 25000,
            'amount_max' => 75000,
            'amount_basis' => 'Preliminary, based on reported monthly revenue.',
            'conditions' => ['Verified revenue would be required.'],
            'required_documents' => ['Six months of bank statements'],
        ]],
        'required_documentation' => [[
            'path_or_lender' => 'Everline Capital',
            'items' => ['Six months of bank statements'],
        ]],
        'borrower_request_snapshot' => [[
            'field' => 'monthly_revenue',
            'value' => 50000,
            'source_status' => 'BORROWER_REPORTED',
        ]],
        'manual_review' => ['required' => false, 'reasons' => []],

        // Internal only. None of this may reach a borrower or a WL partner.
        'executive_underwriting_conclusion' => 'Route to Everline Capital ahead of the secondary desk.',
        'underwriting_analysis' => [['section' => 'Credit', 'finding' => 'Band is thin.', 'evidence_fields' => []]],
        'active_lender_matches' => [[
            'priority' => 1,
            'lender_name' => 'Everline Capital',
            'lender_status' => 'ACTIVE',
            'fit_status' => 'POTENTIAL_FIT',
            'rationale' => 'Meets the preliminary revenue floor.',
            'eligibility_checks' => [],
            'restrictions_or_conditions' => [],
        ]],
        'routing_recommendation' => [
            'primary_product' => 'Working Capital',
            'primary_lender' => 'Everline Capital',
            'stance' => 'CONDITIONAL_REVIEW',
            'next_action' => 'PREFLIGHT',
            'reason' => 'Preliminary fit with Everline Capital.',
        ],
        'final_internal_disposition' => 'Hypothetical scenario assessment only.',
        'closing_tag' => '[Four Corner Holdings – Internal Use Only – Do Not Distribute to Borrower]',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['openai.max_attempts' => 1]);

        AppConfiguration::set(
            'openai_api_key',
            OpenAiResponsesService::encryptSecret('sk-test-key-for-faked-http'),
            AppConfiguration::MODULE_OPENAI
        );

        Lender::query()->create([
            'name' => 'Everline Capital',
            'status' => Lender::STATUS_ACTIVE,
            'iso_status' => Lender::STATUS_ACTIVE,
        ]);
    }

    private function generate(): array
    {
        $application = PreQualificationRequest::create([
            'business_name' => 'Alpha Tech',
            'purpose_of_funding' => 'Working Capital',
            'funding_requested_amount' => 50000,
        ]);

        return [
            $application,
            app(PreQualificationClientAueService::class)->generate($application, self::INTERNAL_RESULT),
        ];
    }

    #[Test]
    public function the_borrower_still_gets_a_result_with_only_the_internal_agent_configured(): void
    {
        // No borrower assessment agent — the one-agent setup.
        [, $result] = $this->generate();

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        // No second model call was made: there is no agent to call.
        Http::assertNothingSent();

        // The borrower reads the internal agent's own words, not a rewording.
        $this->assertSame(
            ['Twenty-six months in business', 'Monthly revenue is consistent'],
            $result['data']['strengths']
        );
        $this->assertSame(['Open a net-30 vendor account'], $result['data']['credit_building_pathway']);
        $this->assertSame('Working Capital', $result['data']['funding_paths'][0]['product_category']);
        $this->assertSame(
            'Provide an updated credit report if you choose to proceed.',
            $result['data']['attention_items'][0]['required_action']
        );
    }

    #[Test]
    public function the_borrower_result_is_a_separate_row_from_the_internal_memo(): void
    {
        [$application] = $this->generate();

        $rows = PreQualificationGptResult::query()->get();

        // generate() writes the borrower row only; the internal memo is its own
        // row, written by the internal evaluation and never merged into this one.
        $this->assertCount(1, $rows);
        $this->assertSame(PreQualificationGptResult::TYPE_CLIENT, $rows->first()->type);
        $this->assertSame($application->id, $rows->first()->pre_qualification_request_id);

        // Labelled for what it is, so it is never mistaken for the output of a
        // borrower assessment agent that never ran.
        $this->assertSame(PreQualificationClientAueService::DERIVED_PROMPT_VERSION, $rows->first()->prompt_version);
        $this->assertNull($rows->first()->model);
    }

    #[Test]
    public function nothing_internal_survives_into_the_borrower_result(): void
    {
        [, $result] = $this->generate();

        $serialized = json_encode($result['data']);

        // The lender, by name, anywhere at any depth — including inside the
        // required_documentation group that was keyed by lender.
        $this->assertStringNotContainsString('Everline', $serialized);

        // The internal memo, the routing decision and the lender matches.
        $this->assertStringNotContainsString('executive_underwriting_conclusion', $serialized);
        $this->assertStringNotContainsString('active_lender_matches', $serialized);
        $this->assertStringNotContainsString('routing_recommendation', $serialized);
        $this->assertStringNotContainsString('underwriting_analysis', $serialized);
        $this->assertStringNotContainsString('final_internal_disposition', $serialized);
        $this->assertStringNotContainsString('Internal Use Only', $serialized);

        // The whitelist decides what crosses, so a field the internal schema
        // gains later cannot reach a borrower by being forgotten.
        $this->assertSame([
            'submission_id',
            'borrower_profile',
            'data_status',
            'borrower_request_snapshot',
            'strengths',
            'attention_items',
            'funding_paths',
            'required_documents',
            'credit_building_pathway',
            'manual_review_required',
        ], array_keys($result['data']));
    }

    #[Test]
    public function the_severity_underwriting_reasoned_by_stays_internal(): void
    {
        [, $result] = $this->generate();

        $item = $result['data']['attention_items'][0];

        // What to act on crosses; how underwriting graded it, and the fields it
        // reasoned over, do not.
        $this->assertSame(['area', 'finding', 'required_action'], array_keys($item));
        $this->assertStringNotContainsString('MATERIAL', json_encode($result['data']));
        $this->assertStringNotContainsString('evidence_fields', json_encode($result['data']));
    }

}
