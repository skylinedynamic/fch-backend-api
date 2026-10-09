<?php

namespace Tests\Integration\PreQualification;

use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Common\AppConfiguration;
use App\Models\Lender\Lender;
use App\Models\PreQualification\PreQualificationAgentConfig;
use App\Services\FCH\Common\PreQualification\AUE\PreQualificationClientAueService;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a knowledge-base-grounded agent may and may not put in front of a
 * borrower.
 *
 * Searching a vector store is the point of the storage setting, but it also
 * means the model is reading internal files and writing from them. Two things
 * must not survive that: the markers naming the files it read, and any lender
 * name those files contain.
 */
class BorrowerSafeAgentOutputTest extends TestCase
{
    use RefreshDatabase;

    private const AGENTS = 'https://api.openai.com/v1/agents';

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['funding_readiness_summary'],
        'properties' => [
            'funding_readiness_summary' => ['type' => 'string'],
            'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ];

    private const SAVED_AGENT = [
        'id' => 'agent_client',
        'name' => 'Borrower Assessment Agent',
        'model' => 'gpt-5.6-luna',
        'instructions' => 'Write the borrower assessment.',
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'fch_client', 'strict' => true, 'schema' => self::SCHEMA]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['openai.max_attempts' => 1]);

        // The credential lives in Settings, not .env, and is read when the
        // client is constructed — so it has to be in place before the service
        // is resolved, or every step short-circuits on "no API key" and the
        // assertions below would pass without the agent ever being called.
        AppConfiguration::set(
            'openai_api_key',
            OpenAiResponsesService::encryptSecret('sk-test-key-for-faked-http'),
            AppConfiguration::MODULE_OPENAI
        );
    }

    private function fakeAgentReturning(array $result): void
    {
        Http::fake(function (Request $request) use ($result) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                return Http::response(self::SAVED_AGENT);
            }

            return Http::response([
                'id' => 'resp_1',
                'model' => self::SAVED_AGENT['model'],
                'output' => [
                    ['type' => 'file_search_call', 'status' => 'completed'],
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => json_encode($result)]],
                    ],
                ],
            ]);
        });
    }

    #[Test]
    public function the_markers_naming_the_knowledge_files_never_reach_the_result(): void
    {
        // A grounded answer carries its citations inline. Left in, the borrower
        // reads the marker mid-sentence — and it names an internal file.
        $this->fakeAgentReturning([
            'funding_readiness_summary' => 'Your revenue supports a working capital review【4:0†Internal_Lender_Matrix_v2.md】.',
            'strengths' => ['Twenty-six months in business 【12:3†Fundability_Standards.md】', 'Revenue is consistent [4:1†source]'],
        ]);

        $result = app(OpenAiAgentsService::class)->runStructuredAgent('agent_client', ['submission_id' => '145']);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        $serialized = json_encode($result['data']);
        $this->assertStringNotContainsString('Internal_Lender_Matrix_v2', $serialized);
        $this->assertStringNotContainsString('Fundability_Standards', $serialized);
        $this->assertStringNotContainsString('†', $serialized);

        // The sentence the model wrote is kept, and reads as written.
        $this->assertSame(
            'Your revenue supports a working capital review.',
            $result['data']['funding_readiness_summary']
        );
        $this->assertSame(
            ['Twenty-six months in business', 'Revenue is consistent'],
            $result['data']['strengths']
        );
    }

    #[Test]
    public function text_with_no_citation_in_it_is_passed_through_exactly_as_written(): void
    {
        $this->fakeAgentReturning([
            'funding_readiness_summary' => 'Your file is ready for review.  Two items remain.',
        ]);

        $result = app(OpenAiAgentsService::class)->runStructuredAgent('agent_client', ['submission_id' => '145']);

        // Untouched means untouched, double space included — the agent's wording
        // is not this code's to tidy.
        $this->assertSame('Your file is ready for review.  Two items remain.', $result['data']['funding_readiness_summary']);
    }

    #[Test]
    public function a_lender_the_agent_named_is_replaced_before_the_borrower_reads_it(): void
    {
        Lender::query()->create(['name' => 'Everline Capital', 'status' => Lender::STATUS_ACTIVE, 'iso_status' => Lender::STATUS_ACTIVE]);

        // The agent searched internal files and quoted a lender inside a field
        // that does cross to the borrower. The name is replaced rather than the
        // whole assessment being thrown away — the borrower still gets their
        // result, and the lender is still protected.
        $application = PreQualificationRequest::create(['business_name' => 'Alpha Tech']);

        $result = app(PreQualificationClientAueService::class)->generate($application, [
            'data_status' => 'COMPLETE',
            'strengths' => ['Revenue clears the Everline Capital floor'],
            'recommended_funding_paths' => [[
                'product_category' => 'Working Capital',
                'amount_basis' => 'Preliminary',
                'conditions' => ['Everline Capital would verify revenue'],
                'required_documents' => [],
            ]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        $serialized = json_encode($result['data']);
        $this->assertStringNotContainsString('Everline', $serialized);
        $this->assertStringContainsString('[redacted]', $serialized);

        // The borrower's result is still produced and stored.
        $this->assertSame(1, PreQualificationGptResult::query()->where('type', PreQualificationGptResult::TYPE_CLIENT)->count());
    }
}
