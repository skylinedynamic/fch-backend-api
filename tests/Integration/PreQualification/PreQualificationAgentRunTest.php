<?php

namespace Tests\Integration\PreQualification;

use App\Services\FCH\Common\PreQualification\AUE\PreQualificationInternalAueService;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How a Pre-Qualification step runs against a faked OpenAI.
 *
 * The step is two calls. The saved agent is read from the Agents API for its
 * instructions, model, reasoning effort and â€” the part that makes this an
 * underwriting step rather than a chat â€” the JSON Schema its result must
 * satisfy. The generation itself then runs on the Responses API, because
 * file_search is a Responses API tool.
 *
 * Nothing in these assertions comes from the codebase: every value sent is one
 * the agent was saved with on the platform.
 */
class PreQualificationAgentRunTest extends TestCase
{
    use RefreshDatabase;

    private const RESPONSES = 'https://api.openai.com/v1/responses';
    private const AGENTS = 'https://api.openai.com/v1/agents';

    /** The result shape the agent is saved to enforce. */
    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['prompt_version', 'overall_stance'],
        'properties' => [
            'prompt_version' => ['type' => 'string'],
            'overall_stance' => ['type' => 'string', 'enum' => ['PROCEED', 'PROCEED_WITH_CONDITIONS', 'DECLINE']],
        ],
    ];

    /** The agent exactly as the host saved it on the OpenAI platform. */
    private const SAVED_AGENT = [
        'id' => 'agent_5fdfc7c079ef427093ae9ca4535c8e98d9f45928981f44b491',
        'name' => 'Prequalification Agent',
        'model' => 'gpt-5.6-luna',
        'instructions' => 'FCH_Internal_Prequalification_AUE_v3 â€” RESPONSES API INSTRUCTIONS',
        'reasoning' => ['effort' => 'medium', 'summary' => 'auto'],
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'fch_internal_prequalification_aue_v3', 'strict' => true, 'schema' => self::SCHEMA]],
    ];

    private const RESULT = ['prompt_version' => 'FCH_Internal_Prequalification_AUE_v3', 'overall_stance' => 'PROCEED_WITH_CONDITIONS'];

    protected function setUp(): void
    {
        parent::setUp();

        // One attempt per test: a retryable failure would otherwise sleep
        // through its back-off twice before the assertion is reached.
        config(['openai.max_attempts' => 1]);
    }

    /**
     * Fakes both halves of a step: the agent read, then the Responses call.
     *
     * @param array|string $result What the model returns as its output text.
     */
    private function fakeRun($result = self::RESULT, array $agent = self::SAVED_AGENT, array $output = []): void
    {
        Http::fake(function (Request $request) use ($result, $agent, $output) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                return Http::response($agent);
            }

            return Http::response([
                'id' => 'resp_1',
                'model' => $agent['model'] ?? null,
                'output' => array_merge($output, [[
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => is_string($result) ? $result : json_encode($result)]],
                ]]),
                'usage' => ['input_tokens' => 2400, 'output_tokens' => 900],
            ]);
        });
    }

    private function runStep(array $overrides = []): array
    {
        return app(OpenAiAgentsService::class)->runStructuredAgent(
            self::SAVED_AGENT['id'],
            ['submission_id' => '145', 'borrower' => ['business_state' => 'AL']],
            $overrides,
            ['step' => 'internal_aue', 'pre_qualification_request_id' => 145]
        );
    }

    #[Test]
    public function the_run_carries_the_agents_own_instructions_model_schema_and_reasoning(): void
    {
        $this->fakeRun();

        $result = $this->runStep();

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(self::RESULT, $result['data']);
        $this->assertSame('gpt-5.6-luna', $result['model']);
        $this->assertSame('resp_1', $result['request_id']);

        // The saved agent is read first, every run: nothing about how it
        // underwrites is copied into this codebase, so an edit saved on the
        // platform applies to the very next evaluation.
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::AGENTS . '/' . self::SAVED_AGENT['id']);

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            return $request->url() === self::RESPONSES
                && $body['model'] === 'gpt-5.6-luna'
                && $body['instructions'] === self::SAVED_AGENT['instructions']
                // The agent's own Text format, sent as it is saved â€” this is
                // what makes the result a structured underwriting outcome.
                && $body['text']['format']['type'] === 'json_schema'
                && $body['text']['format']['schema'] === self::SCHEMA
                && $body['reasoning'] === ['effort' => 'medium']
                // The facts to underwrite, and only those.
                && json_decode($body['input'], true) === ['submission_id' => '145', 'borrower' => ['business_state' => 'AL']];
        });
    }

    #[Test]
    public function a_schema_saved_without_a_name_is_still_sent_with_one(): void
    {
        // The Agents API stores a schema with no name; the Responses API refuses
        // one — "Missing required parameter: 'text.format.name'" — so every run
        // 400s. The name only identifies the schema, so it is supplied from the
        // agent's own name rather than being a thing a host must fix on the
        // platform before their agent will run at all.
        $format = self::SAVED_AGENT['text']['format'];
        unset($format['name']);

        $this->fakeRun(self::RESULT, array_merge(self::SAVED_AGENT, ['text' => ['format' => $format]]));

        $result = $this->runStep();

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $sent = $request->data()['text']['format'];

            // Named after the agent, and the schema itself is untouched.
            return $sent['name'] === 'prequalification_agent'
                && $sent['type'] === 'json_schema'
                && $sent['schema'] === self::SCHEMA;
        });
    }

    #[Test]
    public function a_name_the_agent_does_carry_is_left_alone(): void
    {
        $this->fakeRun();

        $this->runStep();

        Http::assertSent(fn (Request $request) => $request->method() !== 'POST'
            || $request->data()['text']['format']['name'] === 'fch_internal_prequalification_aue_v3');
    }

    #[Test]
    public function the_run_reports_the_agent_that_produced_it(): void
    {
        $this->fakeRun();

        $result = $this->runStep();

        // Carried so a stored row can name the agent and the effort behind it
        // rather than a version constant this class was written against.
        $this->assertSame('Prequalification Agent', $result['prompt_version']);
        $this->assertSame('medium', $result['reasoning_effort']);
    }

    #[Test]
    public function the_stored_prompt_version_is_the_one_the_run_reports_not_the_codes_constant(): void
    {
        $fromResponse = PreQualificationInternalAueService::promptVersionFor(
            ['prompt_version' => 'FCH_Internal_Prequalification_AUE_v4'],
            ['prompt_version' => 'Prequalification Agent']
        );
        $this->assertSame('FCH_Internal_Prequalification_AUE_v4', $fromResponse);

        // An agent whose schema carries no version is labelled by its name.
        $fromAgent = PreQualificationInternalAueService::promptVersionFor([], ['prompt_version' => 'Prequalification Agent']);
        $this->assertSame('Prequalification Agent', $fromAgent);

        // Only with neither does the constant stand in.
        $this->assertSame(
            PreQualificationInternalAueService::PROMPT_VERSION,
            PreQualificationInternalAueService::promptVersionFor([], [])
        );
    }

    #[Test]
    public function every_enabled_storage_id_is_searched_and_its_citations_come_back(): void
    {
        $this->fakeRun(self::RESULT, self::SAVED_AGENT, [
            ['type' => 'file_search_call', 'status' => 'completed', 'queries' => ['minimum time in business']],
        ]);

        $overrides = [
            'tools' => [[
                'type' => 'file_search',
                'vector_store_ids' => ['vs_6abe4d0caac08191960c116f6681bc27', 'vs_6abe0f46ad8c8191b55186af0a98a070'],
                'max_num_results' => 20,
            ]],
            'tool_choice' => ['type' => 'file_search'],
        ];

        $result = $this->runStep($overrides);

        $this->assertTrue($result['ok']);
        // Zero here would mean the evaluation was reasoned without reading a
        // single approved file, which is the whole failure this setting exists
        // to prevent.
        $this->assertSame(1, $result['file_search_calls']);

        Http::assertSent(function (Request $request) use ($overrides) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            // Top level, where the Responses API accepts them â€” both storage
            // ids in the one tool, so whichever holds the passage is read.
            return $body['tools'] === $overrides['tools']
                && $body['tool_choice'] === $overrides['tool_choice']
                && ! isset($body['agent'], $body['agent_id']);
        });
    }

    #[Test]
    public function a_run_with_no_storage_configured_sends_no_tool_at_all(): void
    {
        $this->fakeRun();

        $this->runStep();

        Http::assertSent(fn (Request $request) => $request->method() !== 'POST'
            || ! isset($request->data()['tools'], $request->data()['tool_choice']));
    }

    #[Test]
    public function an_agent_without_a_json_schema_is_refused_before_any_model_is_called(): void
    {
        // An agent left on plain text cannot produce an underwriting result, so
        // it is refused rather than given a schema from this codebase.
        $this->fakeRun(self::RESULT, [
            'id' => self::SAVED_AGENT['id'],
            'name' => 'Prequalification Agent',
            'model' => 'gpt-5.6-luna',
            'instructions' => 'Be helpful.',
        ]);

        $result = $this->runStep();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not set up to return a structured result', $result['error']);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::RESPONSES);
    }

    #[Test]
    public function an_agent_saved_without_a_model_is_reported_rather_than_guessed_at(): void
    {
        $this->fakeRun(self::RESULT, ['id' => self::SAVED_AGENT['id'], 'instructions' => 'x', 'text' => self::SAVED_AGENT['text']]);

        $result = $this->runStep();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no model saved', $result['error']);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::RESPONSES);
    }

    #[Test]
    public function output_that_breaks_the_agents_own_schema_is_rejected_rather_than_returned(): void
    {
        // The model's "strict" flag is not proof its output matched: an
        // unexpected stance would otherwise be stored as an underwriting
        // outcome and rendered to a borrower.
        $this->fakeRun(['prompt_version' => 'v3', 'overall_stance' => 'DEFINITELY_APPROVED']);

        $result = $this->runStep();

        $this->assertFalse($result['ok']);
        $this->assertNull($result['data']);
        $this->assertStringContainsString('schema validation', $result['error']);
    }

    #[Test]
    public function a_result_missing_a_required_field_is_rejected(): void
    {
        $this->fakeRun(['overall_stance' => 'PROCEED']);

        $result = $this->runStep();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('prompt_version', $result['error']);
    }

    #[Test]
    public function prose_where_a_structured_result_was_required_is_reported_not_stored(): void
    {
        $this->fakeRun('The borrower looks fine to me.');

        $result = $this->runStep();

        $this->assertFalse($result['ok']);
        $this->assertNull($result['data']);
        $this->assertStringContainsString('no parseable structured output', $result['error']);
    }

    #[Test]
    public function reasoning_and_verbosity_are_withheld_from_a_model_that_would_reject_them(): void
    {
        // Sending either to a non-reasoning model is a hard 400, so what the
        // agent is saved with is dropped rather than passed on unexamined.
        $this->fakeRun(self::RESULT, array_merge(self::SAVED_AGENT, [
            'model' => 'gpt-4o-mini',
            'text' => array_merge(self::SAVED_AGENT['text'], ['verbosity' => 'low']),
        ]));

        $result = $this->runStep();

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            // The schema still goes â€” only the two fields that model rejects
            // are left out.
            return ! isset($body['reasoning'])
                && ! isset($body['text']['verbosity'])
                && $body['text']['format']['schema'] === self::SCHEMA;
        });
    }

    #[Test]
    public function a_slow_agent_read_is_retried_rather_than_failing_the_evaluation(): void
    {
        // A real underwriting agent is tens of kilobytes of instructions, so a
        // slow link drops the read part-way often enough to matter. Retrying the
        // generation but not the read left one timed-out download failing a whole
        // evaluation with the retry policy standing unused behind it.
        config(['openai.max_attempts' => 3, 'openai.retry_delay_ms' => 0]);

        $reads = 0;

        Http::fake(function (Request $request) use (&$reads) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                $reads++;

                if ($reads === 1) {
                    throw new \Illuminate\Http\Client\ConnectionException(
                        'cURL error 28: Operation timed out after 30002 milliseconds with 15767 out of 40611 bytes received'
                    );
                }

                return Http::response(self::SAVED_AGENT);
            }

            return Http::response([
                'id' => 'resp_1',
                'model' => self::SAVED_AGENT['model'],
                'output' => [[
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => json_encode(self::RESULT)]],
                ]],
            ]);
        });

        $result = $this->runStep();

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(2, $reads, 'the timed-out read should have been tried again');
    }

    /**
     * BUG-060: the first underwriting run failed and only "Try again" worked. A
     * transient failure of the run itself (a 5xx from OpenAI) is now tried
     * again inside the same evaluation, so the first submit completes.
     */
    #[Test]
    public function a_first_run_that_fails_upstream_is_retried_and_completes(): void
    {
        config(['openai.max_attempts' => 3, 'openai.retry_delay_ms' => 0]);

        $runs = 0;

        Http::fake(function (Request $request) use (&$runs) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                return Http::response(self::SAVED_AGENT);
            }

            $runs++;
            if ($runs === 1) {
                return Http::response(['error' => ['message' => 'upstream unavailable']], 503);
            }

            return Http::response([
                'id' => 'resp_1',
                'model' => self::SAVED_AGENT['model'],
                'output' => [[
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => json_encode(self::RESULT)]],
                ]],
            ]);
        });

        $result = $this->runStep();

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(2, $runs, 'the failed first run should have been tried again');
    }

    /**
     * BUG-060: when every attempt fails the step reports the failure at once -
     * the caller marks the application FAILED and the borrower is offered a
     * retry, instead of the application sitting at PENDING.
     */
    #[Test]
    public function a_run_that_fails_on_every_attempt_reports_the_failure(): void
    {
        config(['openai.max_attempts' => 3, 'openai.retry_delay_ms' => 0]);

        $runs = 0;

        Http::fake(function (Request $request) use (&$runs) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                return Http::response(self::SAVED_AGENT);
            }

            $runs++;

            return Http::response(['error' => ['message' => 'upstream unavailable']], 503);
        });

        $result = $this->runStep();

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['error'] ?? null);
        $this->assertSame(3, $runs, 'every allowed attempt should have been made, and no more');
    }

    #[Test]
    public function a_bad_agent_id_is_not_retried(): void
    {
        // A 404 on the id fails identically every time; repeating it only makes
        // the borrower wait longer for the same answer.
        config(['openai.max_attempts' => 3, 'openai.retry_delay_ms' => 0]);

        $reads = 0;

        Http::fake(function (Request $request) use (&$reads) {
            $reads++;

            return Http::response(['error' => ['message' => "No agent found with id 'agent_gone'."]], 404);
        });

        $result = app(OpenAiAgentsService::class)->runStructuredAgent('agent_gone', ['submission_id' => '145']);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $reads);
    }

    #[Test]
    public function an_agent_that_cannot_be_read_fails_the_step_before_any_model_is_called(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => "No agent found with id 'agent_gone'."]], 404)]);

        $result = app(OpenAiAgentsService::class)->runStructuredAgent('agent_gone', ['submission_id' => '145']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No agent found', $result['error']);
        // Nothing is guessed on the agent's behalf: no model is invented and no
        // evaluation is run against one.
        Http::assertNotSent(fn (Request $request) => $request->url() === self::RESPONSES);
    }

    #[Test]
    public function an_api_error_on_the_generation_is_reported_and_not_thrown(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                return Http::response(self::SAVED_AGENT);
            }

            return Http::response(['error' => ['message' => 'Rate limit reached.']], 429);
        });

        $result = $this->runStep();

        $this->assertFalse($result['ok']);
        $this->assertSame(429, $result['http_status']);
        $this->assertStringContainsString('Rate limit', $result['error']);
    }
}

