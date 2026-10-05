<?php

namespace App\Services\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\ChatConversation;
use App\Models\Assistant\ChatMessage;
use App\Models\Borrower\Borrower;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared engine behind both the WLP and Borrower support assistants. Callers
 * (WlpAssistantController, BorrowerAssistantController) only ever pass an
 * assistant_type constant from AiAssistantConfig — which agent runs is keyed
 * off that, never off client input.
 *
 * Each assistant is an Agent saved on the OpenAI platform. This service adds
 * no instructions or wording of its own: the user's conversation goes to the
 * agent and the agent's answer is what the user sees. Editing the agent on the
 * platform changes the very next reply.
 *
 * It does pin down one thing: the file_search tool and the vector stores it
 * reads are sent with every turn, from the assistant's own settings. Knowledge
 * retrieval is what makes these answers trustworthy, so it cannot be left to
 * whether the saved agent happens to have the tool switched on.
 *
 * The sendMessage() return shape stays aligned with the approved
 * FCH_WLP_Assistant_OpenAPI_v1_1.yaml contract, so both frontends keep
 * speaking the same envelope.
 */
class SupportAssistantService
{
    /**
     * Turns of history sent per call. Every turn runs in a fresh agent
     * session, so this bounds both the request size and how far back a very
     * long-running thread's context reaches.
     */
    private const HISTORY_LIMIT = 20;

    public function __construct(protected OpenAiAgentsService $agents)
    {
    }

    /**
     * The caller's most recent open conversation for this assistant, or a
     * fresh one. Always scoped to the authenticated user's own identity.
     */
    public function startOrGetConversation($user, string $assistantType): ChatConversation
    {
        $existing = ChatConversation::query()
            ->where('assistant_type', $assistantType)
            ->where('causer_type', get_class($user))
            ->where('causer_id', $user->getKey())
            ->where('status', 'open')
            ->latest('id')
            ->first();

        return $existing ?? $this->createConversation($user, $assistantType);
    }

    /**
     * Explicitly starts a fresh conversation, closing out whatever the
     * caller's current open one is first — the backing action for a "New
     * conversation" control in the UI, as opposed to startOrGetConversation()
     * silently resuming the same thread forever.
     */
    public function startNewConversation($user, string $assistantType): ChatConversation
    {
        ChatConversation::query()
            ->where('assistant_type', $assistantType)
            ->where('causer_type', get_class($user))
            ->where('causer_id', $user->getKey())
            ->where('status', 'open')
            ->update(['status' => 'closed']);

        return $this->createConversation($user, $assistantType);
    }

    private function createConversation($user, string $assistantType): ChatConversation
    {
        return ChatConversation::create([
            'assistant_type' => $assistantType,
            'partner_id' => $user->partner_id ?? null,
            'borrower_id' => $this->resolveBorrowerId($user),
            'causer_type' => get_class($user),
            'causer_id' => $user->getKey(),
            'status' => 'open',
        ]);
    }

    /**
     * A conversation belonging to the given user for this assistant, or null
     * if it doesn't exist or belongs to someone else — used so a caller can
     * never read, post to, or delete another user's thread by id.
     */
    public function findOwnConversation(int $conversationId, $user, string $assistantType): ?ChatConversation
    {
        $conversation = ChatConversation::find($conversationId);

        if (! $conversation || $conversation->assistant_type !== $assistantType || ! $conversation->isOwnedBy($user)) {
            return null;
        }

        return $conversation;
    }

    /**
     * A conversation belonging to the given user for this assistant, resolved
     * by its external uuid ("session_id" in the OpenAPI contract).
     */
    public function findOwnConversationByUuid(string $uuid, $user, string $assistantType): ?ChatConversation
    {
        $conversation = ChatConversation::where('uuid', $uuid)->first();

        if (! $conversation || $conversation->assistant_type !== $assistantType || ! $conversation->isOwnedBy($user)) {
            return null;
        }

        return $conversation;
    }

    /**
     * Every open/closed conversation belonging to the given user for this
     * assistant — backs the OpenAPI's `GET /wlp-assistant/sessions`.
     */
    public function listOwnConversations($user, string $assistantType)
    {
        return ChatConversation::query()
            ->where('assistant_type', $assistantType)
            ->where('causer_type', get_class($user))
            ->where('causer_id', $user->getKey())
            ->orderByDesc('last_message_at')
            ->get();
    }

    /**
     * Send a user message to the assistant's agent and return the full
     * response envelope: the conversation/messages plus the
     * actions/sources/escalation/policy fields the OpenAPI contract requires
     * alongside the answer text.
     *
     * If the assistant has no agent id saved in Settings, or the agent call
     * fails, this returns a short placeholder flagged as requiring escalation.
     *
     * @return array{
     *     conversation: ChatConversation,
     *     user_message: ChatMessage,
     *     assistant_message: ChatMessage,
     *     actions: array,
     *     navigation: array{required: bool, intent: string, route_key: ?string},
     *     sources: array,
     *     escalation: array{required: bool, reason: ?string, route: ?string},
     *     policy: array{live_data_used: bool, restricted_content_blocked: bool, sensitive_input_detected: bool}
     * }
     */
    public function sendMessage(ChatConversation $conversation, string $rawMessage, $user): array
    {
        return DB::transaction(function () use ($conversation, $rawMessage, $user) {
            [$sanitizedMessage, $sensitiveInputDetected] = $this->sanitizeSensitiveInput($rawMessage);

            $userMessage = $conversation->messages()->create([
                'role' => ChatMessage::ROLE_USER,
                'content' => $sanitizedMessage,
            ]);

            $config = AiAssistantConfig::find($conversation->assistant_type);

            if (! $config->isReady()) {
                return $this->fallbackReply(
                    $conversation,
                    $userMessage,
                    __('assistant.not_available'),
                    'assistant_not_configured',
                    $sensitiveInputDetected
                );
            }

            // Only the user's own message history is sent. The page the user is
            // currently on (request `context.current_page`) is deliberately never
            // read or forwarded.
            $result = $this->agents->runAgent(
                $config->agent_id,
                $this->buildHistory($conversation),
                [
                    'assistant_type' => $conversation->assistant_type,
                    // Stable, non-PII identifiers — never the user's email/name.
                    'conversation' => (string) $conversation->uuid,
                    'user' => hash('sha256', "fch-{$conversation->assistant_type}:{$user->getKey()}"),
                ],
                [
                    'assistant_type' => $conversation->assistant_type,
                    'conversation_id' => $conversation->id,
                    'agent_id' => $config->agent_id,
                    'vector_stores' => count($config->enabledVectorStoreIds()),
                ],
                // The approved knowledge base, sent explicitly so this turn
                // searches it whatever the saved agent's own tools say.
                $config->agentOverrides()
            );

            if (! $result['ok']) {
                Log::warning('Support assistant call failed', [
                    'assistant_type' => $conversation->assistant_type,
                    'conversation_id' => $conversation->id,
                    'error' => $result['error'],
                ]);

                return $this->fallbackReply(
                    $conversation,
                    $userMessage,
                    __('assistant.answer_failed'),
                    'upstream_error',
                    $sensitiveInputDetected
                );
            }

            $sources = $result['sources'] ?? [];

            // Whether this answer actually rests on an approved knowledge file.
            // An answer with no citation behind it is the model speaking from
            // its own memory — the shape every "the assistant said something
            // that isn't in our docs" report takes, so it is recorded and
            // reported rather than left invisible.
            $searchableStores = $config->enabledVectorStoreIds();
            $grounded = empty($searchableStores) || ! empty($sources);

            if (! $grounded) {
                Log::warning('Support assistant answered without citing its knowledge base', [
                    'assistant_type' => $conversation->assistant_type,
                    'conversation_id' => $conversation->id,
                    'agent_id' => $config->agent_id,
                    'vector_store_ids' => $searchableStores,
                    // Zero here means the search never ran at all, which points
                    // at the tool override rather than at the knowledge files.
                    'file_search_calls' => (int) ($result['file_search_calls'] ?? 0),
                    'openai_session_id' => $result['request_id'] ?? null,
                ]);
            }

            // The agent's answer, exactly as it wrote it.
            $reply = $conversation->messages()->create([
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => trim((string) $result['text']),
                'sources' => $sources,
                'openai_request_id' => $result['request_id'] ?? null,
                'usage' => $result['usage'] ?? null,
            ]);

            $conversation->update(['last_message_at' => now()]);

            return [
                'conversation' => $conversation,
                'user_message' => $userMessage,
                'assistant_message' => $reply,
                'actions' => [],
                'navigation' => $this->noNavigation(),
                'sources' => $sources,
                'escalation' => ['required' => false, 'reason' => null, 'route' => null],
                'policy' => $this->policyEnvelope($sensitiveInputDetected, $grounded),
            ];
        });
    }

    /**
     * Persists a canned assistant reply (not configured / upstream failure)
     * and returns the standard envelope, flagged as requiring escalation.
     */
    private function fallbackReply(
        ChatConversation $conversation,
        ChatMessage $userMessage,
        string $content,
        string $reason,
        bool $sensitiveInputDetected
    ): array {
        $reply = $conversation->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => $content,
        ]);

        $conversation->update(['last_message_at' => now()]);

        return [
            'conversation' => $conversation,
            'user_message' => $userMessage,
            'assistant_message' => $reply,
            'actions' => [],
            'navigation' => $this->noNavigation(),
            'sources' => [],
            'escalation' => [
                'required' => true,
                'reason' => $reason,
                'route' => null,
            ],
            'policy' => $this->policyEnvelope($sensitiveInputDetected),
        ];
    }

    /**
     * The contract's navigation field. The assistants offer no in-app
     * navigation, so it always says "none".
     *
     * @return array{required: bool, intent: string, route_key: ?string}
     */
    private function noNavigation(): array
    {
        return ['required' => false, 'intent' => 'none', 'route_key' => null];
    }

    /**
     * @param bool $grounded Whether the reply cited an approved knowledge file.
     *                       True for a fallback reply, which makes no claims of
     *                       its own, and for an assistant with no vector store
     *                       configured, where there is nothing to cite against.
     */
    private function policyEnvelope(bool $sensitiveInputDetected, bool $grounded = true): array
    {
        return [
            // Must remain false in Version 1 — see the OpenAPI contract's `policy.live_data_used` const.
            'live_data_used' => false,
            // The agent's answer is passed through as written; nothing is blocked here.
            'restricted_content_blocked' => false,
            'sensitive_input_detected' => $sensitiveInputDetected,
            // False means the answer cited no approved knowledge file, so it
            // should not be relied on as policy. The reply is still shown: a
            // greeting or a clarifying question legitimately cites nothing, and
            // withholding those would break ordinary conversation.
            'answer_grounded' => $grounded,
        ];
    }

    private function buildHistory(ChatConversation $conversation): array
    {
        return $conversation->messages()
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->sortBy('id')
            ->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    /**
     * Redacts SSNs, passwords, API keys and long opaque secrets from what the
     * user typed before the message is persisted or sent to OpenAI. Mirrors
     * sanitizeSensitiveInput()/`policy.sensitive_input_detected` in
     * WlpAssistantController.php.example.
     *
     * This is a best-effort pattern match, not a guarantee.
     *
     * @return array{0: string, 1: bool}
     */
    private function sanitizeSensitiveInput(string $message): array
    {
        $patterns = [
            '/\b\d{3}-\d{2}-\d{4}\b/' => '[redacted-ssn]',
            '/\bsk-[A-Za-z0-9_-]{10,}\b/i' => '[redacted-api-key]',
            '/\b[A-Za-z0-9\/+=]{35,}\b/' => '[redacted-secret]',
            '/(password|passwd|pwd)\s*[:=]\s*\S+/i' => '$1: [redacted]',
        ];

        $sanitized = $message;
        foreach ($patterns as $pattern => $replacement) {
            $sanitized = preg_replace($pattern, $replacement, $sanitized) ?? $sanitized;
        }

        return [$sanitized, $sanitized !== $message];
    }

    /**
     * The widget's welcome subtitle and "Suggested Questions": exactly what
     * the agent's own instructions on the OpenAI platform say they are.
     *
     * Nothing is written or generated here. The agent is read on every call
     * and two optional sections of its instructions are returned word for
     * word:
     *
     *     ## SUGGESTED QUESTIONS        one question per bullet / numbered line
     *     ## WELCOME MESSAGE            the first line under the heading
     *
     * Edit either section on the platform and the next widget load shows it.
     * An agent without a section (or one that cannot be read, or an assistant
     * that is switched off) shows nothing for it.
     *
     * @return array{welcome_subtitle: ?string, quick_suggestions: string[]}
     */
    public function widgetIntro(AiAssistantConfig $config): array
    {
        $empty = ['welcome_subtitle' => null, 'quick_suggestions' => []];

        if (! $config->isReady()) {
            return $empty;
        }

        $live = $this->agents->retrieveAgent($config->agent_id);

        if (! $live['ok']) {
            return $empty;
        }

        $instructions = (string) ($live['agent']['instructions'] ?? '');

        $suggestions = [];
        foreach ($this->instructionSection($instructions, 'SUGGESTED QUESTIONS') as $line) {
            // A list item: "- text", "* text", "• text" or "1. text".
            if (preg_match('/^(?:[-*\x{2022}]|\d+[.)])\s+(.+)$/u', $line, $m)) {
                $suggestions[] = trim($m[1]);
            }
        }

        return [
            'welcome_subtitle' => $this->instructionSection($instructions, 'WELCOME MESSAGE')[0] ?? null,
            'quick_suggestions' => $suggestions,
        ];
    }

    /**
     * The non-empty lines under a markdown heading with this title, up to the
     * next heading or "---" rule. Empty when the instructions have no such
     * heading.
     *
     * @return string[]
     */
    private function instructionSection(string $instructions, string $title): array
    {
        $lines = [];
        $inside = false;

        foreach (preg_split('/\R/', $instructions) as $line) {
            $line = trim($line);

            if (preg_match('/^#{1,6}\s*(.+?)\s*#*$/', $line, $m)) {
                $inside = strcasecmp(trim($m[1]), $title) === 0;
                continue;
            }

            if ($inside && preg_match('/^-{3,}$/', $line)) {
                break;
            }

            if ($inside && $line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function resolveBorrowerId($user): ?int
    {
        return $user->entity_type === Borrower::class ? (int) $user->entity_id : null;
    }
}
