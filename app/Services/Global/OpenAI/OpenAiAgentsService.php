<?php

namespace App\Services\Global\OpenAI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client for OpenAI's Agents API (https://platform.openai.com/agents): runs a
 * saved Agent (agent_...) exactly as it is configured on the platform.
 *
 * Nothing about the agent is sent from here — instructions, model, reasoning,
 * tools and knowledge are all whatever is saved on the platform. A session
 * copies the saved agent when it is created and never sees later edits, so
 * every chat turn runs in a brand-new session: an edit saved on the platform
 * is used by the very next message, with no deploy and no cache to clear.
 *
 * Requires an API key with the api.agents.read and api.agents.write
 * permissions (plus api.responses.write for the model call itself).
 */
class OpenAiAgentsService extends OpenAiResponsesService
{
    /** Session states in which the turn is still being worked on. */
    private const RUNNING_STATUSES = ['pending', 'in_progress', 'running'];

    private const POLL_INTERVAL_MS = 700;

    /**
     * The saved agent as it is on the platform right now (name, model,
     * instructions, ...). Never cached.
     *
     * @return array{ok: bool, agent: ?array, error: ?string}
     */
    public function retrieveAgent(string $agentId): array
    {
        try {
            $response = $this->client(30)->get("{$this->baseUrl}/agents/{$agentId}");
        } catch (\Throwable $e) {
            return ['ok' => false, 'agent' => null, 'error' => 'Could not reach OpenAI: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'agent' => null, 'error' => $this->errorMessage($response)];
        }

        return ['ok' => true, 'agent' => (array) $response->json(), 'error' => null];
    }

    /**
     * One chat turn against a saved agent.
     *
     * @param array<int, array{role: string, content: string}> $messages Full turn history, oldest first.
     * @param array<string, string> $metadata Non-PII string key/values stored on the OpenAI session.
     *
     * @return array{ok: bool, text: ?string, sources: array, model: ?string, latency_ms: int, usage: ?array, request_id: ?string, error: ?string}
     */
    public function runAgent(string $agentId, array $messages, array $metadata = [], array $logContext = []): array
    {
        $startTime = microtime(true);
        $sessionId = null;

        try {
            // One streamed request carries the whole turn — the session, the
            // agent's work and its answer — so nothing is polled or re-read.
            $response = $this->client($this->timeout)->post("{$this->baseUrl}/agents/sessions", array_filter([
                'agent_id' => $agentId,
                // A support chat needs no sandbox to run code in.
                'environment' => ['type' => 'none'],
                'input' => [[
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $this->asSingleUserMessage($messages)]],
                ]],
                'metadata' => $metadata,
                'stream' => true,
            ]));

            if (! $response->successful()) {
                return $this->agentFailure($logContext, 'API_ERROR', $startTime, $this->errorMessage($response), $response->status());
            }

            ['session' => $session, 'items' => $data] = $this->readTurn($response);
            $sessionId = $session['id'] ?? null;

            if (! is_string($sessionId) || $sessionId === '') {
                return $this->agentFailure($logContext, 'INVALID_JSON', $startTime, 'OpenAI Agents API returned no session id.', $response->status());
            }

            // Safety net: the reply was not a finished stream (a plain JSON
            // session, or a stream cut short), so wait for the turn and read
            // its items the slow way.
            if ($data === null || in_array($session['status'] ?? null, self::RUNNING_STATUSES, true)) {
                $deadline = $startTime + $this->timeout;
                while (in_array($session['status'] ?? null, self::RUNNING_STATUSES, true)) {
                    if (microtime(true) >= $deadline) {
                        return $this->agentFailure($logContext, 'TRANSPORT_ERROR', $startTime, 'OpenAI agent did not finish in time.', null, $sessionId);
                    }

                    usleep(self::POLL_INTERVAL_MS * 1000);

                    $poll = $this->client(30)->get("{$this->baseUrl}/agents/sessions/{$sessionId}");
                    if (! $poll->successful()) {
                        return $this->agentFailure($logContext, 'API_ERROR', $startTime, $this->errorMessage($poll), $poll->status(), $sessionId);
                    }
                    $session = (array) $poll->json();
                }

                $items = $this->client(30)->get("{$this->baseUrl}/agents/sessions/{$sessionId}/items", ['order' => 'asc', 'limit' => 100]);
                if (! $items->successful()) {
                    return $this->agentFailure($logContext, 'API_ERROR', $startTime, $this->errorMessage($items), $items->status(), $sessionId);
                }

                $data = (array) ($items->json()['data'] ?? []);
            }

            if (in_array($session['status'] ?? null, ['failed', 'cancelled', 'requires_action'], true)) {
                return $this->agentFailure($logContext, 'API_ERROR', $startTime, "OpenAI agent session ended as '{$session['status']}'.", null, $sessionId);
            }

            $text = $this->extractFinalAnswer($data);

            if ($text === null) {
                return $this->agentFailure($logContext, 'INVALID_JSON', $startTime, 'OpenAI agent returned no answer text.', $response->status(), $sessionId);
            }

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $model = (string) ($session['agent']['model'] ?? $session['model'] ?? '');
            $usage = is_array($session['usage'] ?? null) ? $session['usage'] : null;
            $this->logOutcome($logContext, $model, 'SUCCESS', $latencyMs, $usage, 200, $sessionId);

            return [
                'ok' => true,
                'text' => $text,
                'sources' => $this->extractCitations(['output' => $data]),
                'model' => $model ?: null,
                'latency_ms' => $latencyMs,
                'usage' => $usage,
                // The session id is what identifies this turn in OpenAI's logs.
                'request_id' => $sessionId,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return $this->agentFailure($logContext, 'TRANSPORT_ERROR', $startTime, 'OpenAI Agents API connection error: ' . $e->getMessage(), null, $sessionId);
        } finally {
            // The transcript already lives in chat_messages; don't leave a
            // second copy of the conversation behind on OpenAI. Done after
            // the user has their answer — the delete itself takes seconds.
            if ($sessionId) {
                $client = $this->client(15);
                $url = "{$this->baseUrl}/agents/sessions/{$sessionId}";

                defer(function () use ($client, $url) {
                    try {
                        $client->delete($url);
                    } catch (\Throwable $e) {
                        // Best effort only.
                    }
                })->always();
            }
        }
    }

    /**
     * Reads a session-create reply. A streamed reply (server-sent events)
     * yields the session as of its last event plus every finished item of the
     * turn; a plain JSON reply yields just the session, with items left null
     * for the caller to fetch.
     *
     * @return array{session: array, items: ?array}
     */
    protected function readTurn($response): array
    {
        $body = (string) $response->body();

        if (! str_contains((string) $response->header('Content-Type'), 'text/event-stream') && ! preg_match('/^\s*(event|data):/', $body)) {
            return ['session' => (array) $response->json(), 'items' => null];
        }

        $session = [];
        $items = [];

        foreach (preg_split('/\R{2,}/', trim($body)) as $block) {
            $payload = '';
            foreach (preg_split('/\R/', $block) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $payload .= ltrim(substr($line, 5));
                }
            }

            $event = json_decode($payload, true);
            if (! is_array($event)) {
                continue;
            }

            if (is_array($event['session'] ?? null)) {
                $session = $event['session'];
            }

            $type = (string) ($event['type'] ?? '');

            if ($type === 'agent.session.turn.item.done' && is_array($event['item'] ?? null)) {
                $items[] = $event['item'];
            } elseif (in_array($type, ['agent.session.turn.failed', 'agent.session.turn.cancelled'], true)) {
                $session['status'] = str_ends_with($type, 'failed') ? 'failed' : 'cancelled';
            }
        }

        return ['session' => $session, 'items' => $items];
    }

    /**
     * The agent's reply: its message marked as the final answer, or — when no
     * message carries a phase — its last message.
     */
    protected function extractFinalAnswer(array $items): ?string
    {
        $last = null;
        $final = null;

        foreach ($items as $item) {
            if (! is_array($item) || ($item['role'] ?? null) !== 'assistant') {
                continue;
            }

            $text = $this->itemText($item['content'] ?? null);
            if ($text === '') {
                continue;
            }

            $last = $text;
            if (($item['phase'] ?? null) === 'final_answer') {
                $final = $text;
            }
        }

        return $final ?? $last;
    }

    /**
     * A new session accepts user messages only — an earlier assistant reply
     * cannot be sent back as its own message. So a first message goes as it
     * is, and a follow-up carries the earlier turns as a transcript ahead of
     * the new message, which is what keeps a fresh session per turn (and so
     * real-time agent updates) compatible with a multi-turn conversation.
     */
    protected function asSingleUserMessage(array $messages): string
    {
        $current = array_pop($messages);
        $text = (string) ($current['content'] ?? '');

        if (empty($messages)) {
            return $text;
        }

        $transcript = implode("\n\n", array_map(
            static fn (array $m) => ($m['role'] === 'assistant' ? 'Assistant' : 'User') . ': ' . $m['content'],
            $messages
        ));

        return "Earlier in this conversation:\n\n{$transcript}\n\nNew message from the user:\n\n{$text}";
    }

    private function itemText($content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        $parts = [];
        foreach ((array) $content as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode('', $parts));
    }

    private function client(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)->withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'OpenAI-Beta' => 'agents=v1',
        ]);
    }

    private function errorMessage($response): string
    {
        $message = $response->json()['error']['message'] ?? $response->body();

        return "OpenAI Agents API error ({$response->status()}): {$message}";
    }

    private function agentFailure(array $logContext, string $kind, float $startTime, string $error, ?int $httpStatus = null, ?string $sessionId = null): array
    {
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $this->logOutcome($logContext, '', $kind, $latencyMs, null, $httpStatus, $sessionId);

        return [
            'ok' => false,
            'text' => null,
            'sources' => [],
            'model' => null,
            'latency_ms' => $latencyMs,
            'usage' => null,
            'failure_kind' => $kind,
            'http_status' => $httpStatus,
            'request_id' => $sessionId,
            'error' => $error,
        ];
    }
}
