<?php

namespace Tests\Integration\Assistant;

use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OpenAiAgentsService against a faked OpenAI: the request it sends for a chat
 * turn, and how it reads the answer back.
 *
 * A turn is two calls. The saved agent is read from the Agents API for its
 * wording, model and reasoning; the turn itself runs on the Responses API,
 * because file_search is a Responses API tool and an Agents session rejects
 * it outright. Both halves are asserted here.
 */
class OpenAiAgentsServiceTest extends TestCase
{
    use RefreshDatabase;

    private const RESPONSES = 'https://api.openai.com/v1/responses';
    private const AGENTS = 'https://api.openai.com/v1/agents';

    private const SAVED_AGENT = [
        'id' => 'agent_abc',
        'model' => 'gpt-6-astra',
        'instructions' => 'Be helpful.',
    ];

    /**
     * Fakes both halves of a turn: the agent read, then the Responses call.
     *
     * @param array $output The Responses API `output` items.
     */
    private function fakeTurn(array $output, array $agent = self::SAVED_AGENT, array $response = []): void
    {
        Http::fake(function (Request $request) use ($output, $agent, $response) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                return Http::response($agent);
            }

            return Http::response(array_merge([
                'id' => 'resp_1',
                'output' => $output,
                'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
            ], $response));
        });
    }

    /** The shape the Responses API returns an assistant message in. */
    private function answer(string $text, array $annotations = []): array
    {
        return [
            'type' => 'message',
            'role' => 'assistant',
            'content' => [array_filter([
                'type' => 'output_text',
                'text' => $text,
                'annotations' => $annotations ?: null,
            ])],
        ];
    }

    #[Test]
    public function a_turn_runs_on_the_responses_api_carrying_the_agents_own_wording_and_model(): void
    {
        $this->fakeTurn([$this->answer('The minimum is 5 years.')]);

        $result = app(OpenAiAgentsService::class)->runAgent(
            'agent_abc',
            [['role' => 'user', 'content' => 'Hi']],
            ['assistant_type' => 'borrower_support']
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('The minimum is 5 years.', $result['text']);
        $this->assertSame('gpt-6-astra', $result['model']);
        $this->assertSame('resp_1', $result['request_id']);

        // The saved agent is read first: its wording and model are not copied
        // into this codebase, so an edit on the platform applies immediately.
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::AGENTS . '/agent_abc');

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            return $request->url() === self::RESPONSES
                && $body['model'] === 'gpt-6-astra'
                && $body['instructions'] === 'Be helpful.'
                && $body['metadata'] === ['assistant_type' => 'borrower_support']
                && $body['input'] === [[
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => 'Hi']],
                ]]
                // No knowledge base passed, so no tool is sent and the model
                // answers from its instructions alone.
                && ! isset($body['tools'], $body['tool_choice']);
        });

        // An Agents session is never created: that API cannot run file_search.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/agents/sessions'));
    }

    #[Test]
    public function a_follow_up_sends_the_earlier_turns_in_their_own_roles(): void
    {
        $this->fakeTurn([$this->answer('Yes.')]);

        app(OpenAiAgentsService::class)->runAgent('agent_abc', [
            ['role' => 'user', 'content' => 'What is the minimum?'],
            ['role' => 'assistant', 'content' => '5 years.'],
            ['role' => 'user', 'content' => 'Is 4 years enough?'],
        ]);

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            // The Responses API takes the turns as they happened, so the model
            // sees its own earlier reply rather than a transcript of it. Input
            // and output text are typed differently.
            return $request->data()['input'] === [
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'What is the minimum?']]],
                ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '5 years.']]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Is 4 years enough?']]],
            ];
        });
    }

    #[Test]
    public function an_empty_message_is_dropped_rather_than_sent_as_a_blank_turn(): void
    {
        $this->fakeTurn([$this->answer('Hello.')]);

        app(OpenAiAgentsService::class)->runAgent('agent_abc', [
            ['role' => 'user', 'content' => '   '],
            ['role' => 'user', 'content' => 'Hello?'],
        ]);

        Http::assertSent(fn (Request $request) => $request->method() !== 'POST'
            || count($request->data()['input']) === 1);
    }

    #[Test]
    public function the_knowledge_base_is_sent_as_responses_api_tools_not_an_inline_agent(): void
    {
        $this->fakeTurn([$this->answer('Three years.')]);

        $overrides = [
            'tools' => [['type' => 'file_search', 'vector_store_ids' => ['vs_abc123'], 'max_num_results' => 20]],
            'tool_choice' => ['type' => 'file_search'],
        ];

        app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']], [], [], $overrides);

        Http::assertSent(function (Request $request) use ($overrides) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            // Top level, where the Responses API accepts them. Nested under an
            // "agent" key — as an Agents session needs — OpenAI rejects both
            // the tool and the tool_choice with a 400.
            return $body['tools'] === $overrides['tools']
                && $body['tool_choice'] === $overrides['tool_choice']
                && ! isset($body['agent'], $body['agent_id']);
        });
    }

    #[Test]
    public function the_agents_saved_reasoning_effort_is_sent_for_a_model_that_accepts_it(): void
    {
        $this->fakeTurn(
            [$this->answer('Three years.')],
            ['id' => 'agent_abc', 'model' => 'gpt-5-mini', 'instructions' => 'Be helpful.', 'reasoning' => ['effort' => 'high']]
        );

        app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn (Request $request) => $request->method() !== 'POST'
            || $request->data()['reasoning'] === ['effort' => 'high']);
    }

    #[Test]
    public function reasoning_effort_is_withheld_from_a_model_that_would_reject_it(): void
    {
        // Sending reasoning.effort to a non-reasoning model is a hard 400.
        $this->fakeTurn(
            [$this->answer('Three years.')],
            ['id' => 'agent_abc', 'model' => 'gpt-4o-mini', 'instructions' => 'Be helpful.', 'reasoning' => ['effort' => 'high']]
        );

        app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn (Request $request) => $request->method() !== 'POST'
            || ! isset($request->data()['reasoning']));
    }

    #[Test]
    public function a_turn_reports_how_often_the_knowledge_base_was_searched(): void
    {
        $this->fakeTurn([
            ['type' => 'file_search_call', 'status' => 'completed', 'queries' => ['minimum time in business']],
            $this->answer('Three years.', [
                ['type' => 'file_citation', 'filename' => 'Borrower_Page_02_Fundability_Standards_v1_0.md'],
                // The same file cited twice is one source, not two.
                ['type' => 'file_citation', 'filename' => 'Borrower_Page_02_Fundability_Standards_v1_0.md'],
            ]),
        ]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame(1, $result['file_search_calls']);
        $this->assertSame([['title' => 'Borrower_Page_02_Fundability_Standards_v1_0.md']], $result['sources']);
    }

    #[Test]
    public function inline_citation_markers_are_removed_from_the_answer_but_the_sources_are_kept(): void
    {
        $this->fakeTurn([
            ['type' => 'file_search_call', 'status' => 'completed', 'queries' => ['locked tier']],
            $this->answer(
                "Contact your support team for a review. \u{E200}filecite\u{E202}turn0file1\u{E202}turn0file6\u{E201}\n\nSee the guide \u{E200}filecite\u{E202}turn0file2\u{E201}.",
                [['type' => 'file_citation', 'filename' => 'Borrower_Page_05_Credit_Builder_v1_0.md']]
            ),
        ]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame("Contact your support team for a review.\n\nSee the guide.", $result['text']);
        $this->assertSame([['title' => 'Borrower_Page_05_Credit_Builder_v1_0.md']], $result['sources']);
    }

    #[Test]
    public function an_answer_produced_without_searching_reports_no_file_search_calls(): void
    {
        $this->fakeTurn([$this->answer('Probably around three years.')]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['file_search_calls']);
        $this->assertSame([], $result['sources']);
    }

    #[Test]
    public function an_api_error_on_the_turn_is_reported_and_not_thrown(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                return Http::response(self::SAVED_AGENT);
            }

            return Http::response(['error' => ['message' => 'Invalid value: \'file_search\'.']], 400);
        });

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['http_status']);
        $this->assertStringContainsString('file_search', $result['error']);
    }

    #[Test]
    public function an_agent_that_cannot_be_read_fails_the_turn_before_any_model_is_called(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => "No agent found with id 'agent_gone'."]], 404)]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_gone', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No agent found', $result['error']);

        // Nothing is guessed on the agent's behalf: no model is invented and
        // no turn is run against one.
        Http::assertNotSent(fn (Request $request) => $request->url() === self::RESPONSES);
    }

    #[Test]
    public function an_agent_saved_without_a_model_is_reported_rather_than_guessed_at(): void
    {
        $this->fakeTurn([$this->answer('Hi.')], ['id' => 'agent_abc', 'instructions' => 'Be helpful.']);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no model saved', $result['error']);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::RESPONSES);
    }

    #[Test]
    public function a_turn_that_returns_no_text_is_reported_rather_than_shown_as_an_empty_reply(): void
    {
        $this->fakeTurn([['type' => 'file_search_call', 'status' => 'completed']]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no answer text', $result['error']);
    }

    #[Test]
    public function retrieve_agent_returns_the_agent_as_saved_on_the_platform(): void
    {
        Http::fake(['*' => Http::response(['id' => 'agent_abc', 'name' => 'FCH Borrower Support Assistant', 'model' => 'gpt-6-astra', 'instructions' => 'Be helpful.'])]);

        $result = app(OpenAiAgentsService::class)->retrieveAgent('agent_abc');

        $this->assertTrue($result['ok']);
        $this->assertSame('Be helpful.', $result['agent']['instructions']);
    }

    #[Test]
    public function a_vector_store_is_read_from_the_responses_api_without_the_agents_beta_header(): void
    {
        Http::fake(['*' => Http::response(['id' => 'vs_abc123', 'name' => 'Borrower Assistant - Approved Knowledge v1.6', 'status' => 'completed'])]);

        $result = app(OpenAiAgentsService::class)->retrieveVectorStore('vs_abc123');

        $this->assertTrue($result['ok']);
        $this->assertSame('Borrower Assistant - Approved Knowledge v1.6', $result['store']['name']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/vector_stores/vs_abc123'
            && ! $request->hasHeader('OpenAI-Beta'));
    }

    #[Test]
    public function an_unreachable_vector_store_is_reported_and_not_thrown(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'No such vector store: vs_typo']], 404)]);

        $result = app(OpenAiAgentsService::class)->retrieveVectorStore('vs_typo');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No such vector store', $result['error']);
    }
}
