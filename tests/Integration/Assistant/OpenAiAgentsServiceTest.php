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
 * turn (the saved agent's id and the conversation — nothing else), and how it
 * reads the answer back.
 */
class OpenAiAgentsServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SESSIONS = 'https://api.openai.com/v1/agents/sessions';

    protected function setUp(): void
    {
        parent::setUp();

        // The session delete is deferred until after the response in real
        // requests; run it inline here so it can be asserted.
        $this->withoutDefer();
    }

    /** A streamed (server-sent events) turn, as the Agents API returns it for stream: true. */
    private function sse(array $events): string
    {
        return implode('', array_map(
            fn (array $e) => "event: {$e['type']}\ndata: " . json_encode($e) . "\n\n",
            $events
        ));
    }

    #[Test]
    public function a_streamed_turn_is_answered_from_the_one_request_with_no_polling(): void
    {
        $session = ['id' => 'sess_1', 'status' => 'in_progress', 'agent' => ['model' => 'gpt-6-astra']];

        Http::fake(function (Request $request) use ($session) {
            if ($request->method() === 'POST') {
                return Http::response($this->sse([
                    ['type' => 'agent.session.created', 'session' => $session],
                    ['type' => 'agent.session.turn.item.done', 'item' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hi']]]],
                    ['type' => 'agent.session.turn.item.done', 'item' => ['type' => 'reasoning', 'summary' => []]],
                    ['type' => 'agent.session.turn.output_text.delta', 'delta' => 'The minimum'],
                    ['type' => 'agent.session.turn.item.done', 'item' => ['type' => 'message', 'role' => 'assistant', 'phase' => 'final_answer', 'content' => [['type' => 'output_text', 'text' => 'The minimum is 3 years.']]]],
                    ['type' => 'agent.session.idle', 'session' => ['status' => 'idle'] + $session],
                ]), 201, ['Content-Type' => 'text/event-stream']);
            }

            return Http::response(['id' => 'sess_1', 'deleted' => true]);
        });

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertTrue($result['ok']);
        $this->assertSame('The minimum is 3 years.', $result['text']);
        $this->assertSame('gpt-6-astra', $result['model']);
        $this->assertSame('sess_1', $result['request_id']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->data()['stream'] === true);
        // No status polling and no separate read of the items.
        Http::assertNotSent(fn (Request $request) => $request->method() === 'GET');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::SESSIONS . '/sess_1');
    }

    #[Test]
    public function a_streamed_turn_that_fails_is_reported_as_an_error(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response($this->sse([
                    ['type' => 'agent.session.created', 'session' => ['id' => 'sess_1', 'status' => 'in_progress']],
                    ['type' => 'agent.session.turn.failed', 'turn' => ['id' => 'turn_1']],
                ]), 201, ['Content-Type' => 'text/event-stream']);
            }

            return Http::response(['id' => 'sess_1', 'deleted' => true]);
        });

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed', $result['error']);
    }

    private function fakeTurn(array $items, array $session = ['id' => 'sess_1', 'status' => 'idle']): void
    {
        Http::fake(function (Request $request) use ($items, $session) {
            if ($request->method() === 'POST' && $request->url() === self::SESSIONS) {
                return Http::response($session);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/items')) {
                return Http::response(['data' => $items, 'has_more' => false]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['id' => 'sess_1', 'status' => 'idle']);
            }

            return Http::response(['id' => 'sess_1', 'deleted' => true]);
        });
    }

    #[Test]
    public function a_turn_sends_only_the_agent_id_and_the_conversation_then_returns_the_final_answer(): void
    {
        $this->fakeTurn([
            ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hi']]],
            ['type' => 'message', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => 'Looking that up.']]],
            ['type' => 'message', 'role' => 'assistant', 'phase' => 'final_answer', 'content' => [['type' => 'output_text', 'text' => 'The minimum is 5 years.']]],
        ]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']], ['assistant_type' => 'borrower_support']);

        $this->assertTrue($result['ok']);
        $this->assertSame('The minimum is 5 years.', $result['text']);
        $this->assertSame('sess_1', $result['request_id']);

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            return $request->url() === self::SESSIONS
                && $request->hasHeader('OpenAI-Beta', 'agents=v1')
                && $body['agent_id'] === 'agent_abc'
                && $body['input'] === [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hi']]]]
                // The saved agent supplies these; sending any would override the
                // platform. With no tools passed, not even "agent" is sent.
                && ! isset($body['agent'], $body['instructions'], $body['model'], $body['tools']);
        });

        // The session is removed afterwards: the transcript lives in chat_messages.
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::SESSIONS . '/sess_1');
    }

    #[Test]
    public function a_follow_up_goes_as_one_user_message_carrying_the_earlier_turns(): void
    {
        // The Agents API rejects any input role but "user".
        $this->fakeTurn([['type' => 'message', 'role' => 'assistant', 'phase' => 'final_answer', 'content' => [['type' => 'output_text', 'text' => 'Yes.']]]]);

        app(OpenAiAgentsService::class)->runAgent('agent_abc', [
            ['role' => 'user', 'content' => 'What is the minimum?'],
            ['role' => 'assistant', 'content' => '5 years.'],
            ['role' => 'user', 'content' => 'Is 4 years enough?'],
        ]);

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $input = $request->data()['input'];
            $text = $input[0]['content'][0]['text'];

            return count($input) === 1
                && $input[0]['role'] === 'user'
                && str_contains($text, 'User: What is the minimum?')
                && str_contains($text, 'Assistant: 5 years.')
                && str_ends_with($text, 'Is 4 years enough?');
        });
    }

    #[Test]
    public function a_session_still_running_after_create_is_polled_until_it_finishes(): void
    {
        $this->fakeTurn(
            [['type' => 'message', 'role' => 'assistant', 'content' => 'Plain string answer.']],
            ['id' => 'sess_1', 'status' => 'in_progress']
        );

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertTrue($result['ok']);
        $this->assertSame('Plain string answer.', $result['text']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === self::SESSIONS . '/sess_1');
    }

    #[Test]
    public function an_api_error_is_reported_and_not_thrown(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Managed Agents requests require the api.agents.write permission']], 403)]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertFalse($result['ok']);
        $this->assertSame(403, $result['http_status']);
        $this->assertStringContainsString('api.agents.write', $result['error']);
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
    public function overrides_are_sent_as_an_inline_agent_beside_the_saved_agent_id(): void
    {
        $this->fakeTurn([[
            'type' => 'message',
            'role' => 'assistant',
            'phase' => 'final_answer',
            'content' => [['type' => 'output_text', 'text' => 'Three years.']],
        ]]);

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

            return $body['agent_id'] === 'agent_abc'
                // Only what was passed is overridden: model, instructions and
                // reasoning still come from the agent saved on the platform.
                && $body['agent'] === $overrides
                && ! isset($body['agent']['model'], $body['agent']['instructions']);
        });
    }

    #[Test]
    public function a_turn_reports_how_often_the_knowledge_base_was_searched(): void
    {
        $this->fakeTurn([
            ['type' => 'file_search_call', 'status' => 'completed', 'queries' => ['minimum time in business']],
            [
                'type' => 'message',
                'role' => 'assistant',
                'phase' => 'final_answer',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Three years.',
                    'annotations' => [
                        ['type' => 'file_citation', 'filename' => 'Borrower_Page_02_Fundability_Standards_v1_0.md'],
                        // The same file cited twice is one source, not two.
                        ['type' => 'file_citation', 'filename' => 'Borrower_Page_02_Fundability_Standards_v1_0.md'],
                    ],
                ]],
            ],
        ]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame(1, $result['file_search_calls']);
        $this->assertSame([['title' => 'Borrower_Page_02_Fundability_Standards_v1_0.md']], $result['sources']);
    }

    #[Test]
    public function an_answer_produced_without_searching_reports_no_file_search_calls(): void
    {
        $this->fakeTurn([[
            'type' => 'message',
            'role' => 'assistant',
            'phase' => 'final_answer',
            'content' => [['type' => 'output_text', 'text' => 'Probably around three years.']],
        ]]);

        $result = app(OpenAiAgentsService::class)->runAgent('agent_abc', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['file_search_calls']);
        $this->assertSame([], $result['sources']);
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
