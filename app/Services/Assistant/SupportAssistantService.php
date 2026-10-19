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
 * no guidance of its own on what to say: the user's conversation goes to the
 * agent and the agent's answer is what the user sees. Editing the agent on the
 * platform changes the very next reply.
 *
 * It pins down two things the agent cannot do alone:
 *
 *  - Knowledge retrieval. The file_search tool and the vector stores it reads
 *    are sent with every turn, from the assistant's own settings, so it is not
 *    left to whether the saved agent happens to have the tool switched on.
 *
 *  - Take Me There. The agents' instructions offer that action for approved
 *    portal pages and leave the destination to the application. So every turn
 *    asks for the answer together with the destination's route_key, chosen
 *    from config/assistant.php's map, and a "Take Me There" line becomes a
 *    real button to that page — or is removed when there is no approved
 *    destination behind it. The agent is never told what to answer, only how
 *    to hand the answer back.
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

    /** The only navigation intent the agent may return; anything else is "no navigation". */
    private const NAVIGATION_INTENT = 'portal_navigation';

    /** The action label the agents' instructions use, and the button's text. */
    private const CTA_LABEL = 'Take Me There';

    /** The placeholder a portal's chat widget swaps for the button. */
    private const ACTION_TOKEN_PATTERN = '/\[\[ACTION:[^\]]*\]\]/i';

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
            // Newest first by creation, not by last message: a session just
            // started has no message yet, and sorting it last would make the
            // widget reopen the conversation the user has just left.
            ->orderByDesc('id')
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
                // searches it whatever the saved agent's own tools say, plus
                // the reply format that carries the Take Me There destination.
                array_merge($config->agentOverrides(), $this->replyFormat($config))
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

            $parsed = $this->parseReply((string) ($result['text'] ?? ''));

            if ($parsed === null) {
                Log::warning('Support assistant returned an unusable structured reply', [
                    'assistant_type' => $conversation->assistant_type,
                    'conversation_id' => $conversation->id,
                    'openai_session_id' => $result['request_id'] ?? null,
                ]);

                return $this->fallbackReply(
                    $conversation,
                    $userMessage,
                    __('assistant.answer_failed'),
                    'invalid_response',
                    $sensitiveInputDetected
                );
            }

            [$text, $actions, $navigation] = $this->applyNavigation($parsed['answer'], $parsed['navigation'], $config);

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

            // The agent's answer as it wrote it, with its Take Me There line
            // turned into the button's placeholder.
            $reply = $conversation->messages()->create([
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => $text,
                'actions' => $actions,
                'navigation' => $navigation,
                'sources' => $sources,
                'openai_request_id' => $result['request_id'] ?? null,
                'usage' => $result['usage'] ?? null,
            ]);

            $conversation->update(['last_message_at' => now()]);

            return [
                'conversation' => $conversation,
                'user_message' => $userMessage,
                'assistant_message' => $reply,
                'actions' => $actions,
                'navigation' => $navigation,
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
     * The contract's navigation field for a reply with no Take Me There action.
     *
     * @return array{required: bool, intent: string, route_key: ?string}
     */
    private function noNavigation(): array
    {
        return ['required' => false, 'intent' => 'none', 'route_key' => null];
    }

    /**
     * What is added to a turn so the answer comes back with its Take Me There
     * destination: a strict JSON reply format, and the note that explains it.
     *
     * route_key is an enum of exactly this assistant's approved keys (plus
     * null), so the agent cannot even express an unapproved destination; the
     * key is still re-checked against the map when the reply arrives. Empty
     * for an assistant with no destinations, which then answers in plain text.
     *
     * @return array<string, mixed>
     */
    private function replyFormat(AiAssistantConfig $config): array
    {
        $map = $config->navigationMap();

        if ($map === []) {
            return [];
        }

        $destinations = '';
        foreach ($map as $key => $entry) {
            $destinations .= "  {$entry['label']} -> {$key}\n";
        }

        $label = self::CTA_LABEL;
        $intent = self::NAVIGATION_INTENT;
        // A real page name from this assistant's own map, for the note's example.
        $example = (string) (array_key_last($map) ? $map[array_key_last($map)]['label'] : '');

        return [
            'instructions_append' => "\n\n---\nRESPONSE FORMAT (integration contract set by the platform backend; it applies on top of every rule above and does not change any of them):\n"
                . "Return a JSON object with exactly two fields: \"answer\" and \"navigation\".\n"
                . "- \"answer\": your complete reply to the user, worded and formatted exactly as the rules above require (same bold, lists, numbering and line breaks you would otherwise use). Wherever the rules above call for the {$label} action, output the label **{$label}** by itself on its own line; the portal replaces that line with a real {$label} button. Keep that one line bare, and do not mention {$label} anywhere else in the answer (for example, never \"select {$label}\"). Never write a URL, route, route key or internal identifier in the answer.\n"
                . "- This format changes nothing about how you name pages. In the answer's own sentences, keep naming every portal page or section by its exact visible name in bold, exactly as the rules above require (for example **{$example}**), rather than a vague phrase such as \"in your portal\".\n"
                . "- Whenever the answer tells the user to open, review, check, use or go to a page that is in the approved destinations below, that destination directly helps: include the {$label} line for it. If the answer names several, use the one the user should open first.\n"
                . "- \"navigation\": describes that same action so the portal knows where the button goes.\n"
                . "  * If (and only if) the answer contains {$label}: {\"required\": true, \"intent\": \"{$intent}\", \"route_key\": \"<key>\"}.\n"
                . "  * Otherwise: {\"required\": false, \"intent\": \"none\", \"route_key\": null}.\n"
                . "  * <key> is the approved destination the user is ASKING for, chosen only from the list below. It is never the page the user is currently on and never a default. For a section inside a page (for example a Settings section such as Branding or Email Configuration), use that section's own key when it is in the list below, so the button opens the section directly; use the parent page's key only when the section has no entry of its own, and then state which section to select in the answer.\n"
                . "  * Every {$label} in one answer must go to the same route_key. If destinations differ, offer one action and describe the others in text.\n"
                . "  * If the destination is not in the list, do not output {$label}; give text guidance instead.\n"
                . "Approved destinations (visible name -> route_key):\n{$destinations}---",
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'assistant_reply',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['answer', 'navigation'],
                        'properties' => [
                            'answer' => ['type' => 'string'],
                            'navigation' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['required', 'intent', 'route_key'],
                                'properties' => [
                                    'required' => ['type' => 'boolean'],
                                    'intent' => ['type' => 'string', 'enum' => [$intent, 'none']],
                                    'route_key' => [
                                        'type' => ['string', 'null'],
                                        'enum' => [...array_keys($map), null],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The agent's reply as an answer plus its navigation claim.
     *
     * A reply that is not JSON at all is an answer in plain text — what an
     * assistant with no destinations returns — and is shown as it is, with no
     * navigation. JSON that cannot be read (a reply cut short) or that carries
     * no answer returns null, so the caller shows a safe error rather than raw
     * JSON.
     *
     * @return ?array{answer: string, navigation: ?array}
     */
    private function parseReply(string $text): ?array
    {
        $text = trim($text);

        if (! str_starts_with($text, '{')) {
            return $text === '' ? null : ['answer' => $text, 'navigation' => null];
        }

        $data = json_decode($text, true);

        if (! is_array($data) || ! is_string($data['answer'] ?? null)) {
            return null;
        }

        // Citation markers sit inside the answer string, so they are removed
        // here, after decoding — see OpenAiAgentsService::stripCitationMarkers().
        $answer = $this->agents->stripCitationMarkers($data['answer']);

        if ($answer === '') {
            return null;
        }

        return [
            'answer' => $answer,
            'navigation' => is_array($data['navigation'] ?? null) ? $data['navigation'] : null,
        ];
    }

    /**
     * Checks the agent's navigation claim against the server-owned map. Only
     * an explicit {required: true, intent: portal_navigation} with a route_key
     * that exists for THIS assistant resolves; everything else is "no
     * navigation". There is deliberately no default destination.
     *
     * @return ?array{route_key: string, label: string, path: string}
     */
    private function resolveNavigation(?array $raw, AiAssistantConfig $config): ?array
    {
        if (! is_array($raw) || ($raw['required'] ?? false) !== true) {
            return null;
        }

        $key = $raw['route_key'] ?? null;
        $map = $config->navigationMap();

        if (($raw['intent'] ?? null) !== self::NAVIGATION_INTENT || ! is_string($key) || ! isset($map[$key])) {
            Log::warning('Support assistant returned an unapproved navigation value; ignored', [
                'assistant_type' => $config->assistant_type,
                'intent' => is_scalar($raw['intent'] ?? null) ? $raw['intent'] : null,
                'route_key' => is_scalar($key) ? $key : null,
            ]);

            return null;
        }

        return ['route_key' => $key, 'label' => $map[$key]['label'], 'path' => $map[$key]['path']];
    }

    /**
     * Turns the answer's "Take Me There" line(s) into the
     * `[[ACTION:navigate_<route_key>]]` placeholder a portal renders as a
     * button, and builds the action from the server-owned map only.
     *
     *  - Approved destination: every Take Me There line becomes the button; if
     *    the agent named a destination but wrote no such line, the button is
     *    added at the end.
     *  - No approved destination: the line is removed, so the user is never
     *    told about a button that is not there. Whatever followed the label on
     *    that line ("Then select **Test Mail**") is kept as plain guidance.
     *  - A placeholder written by the agent itself is stripped first: only
     *    this method may create one.
     *
     * The line may be bold, bulleted or numbered, and may carry the forms the
     * instructions allow after the label — "Take Me There -> Then select X" —
     * or the destination's own name, which the button makes redundant.
     *
     * @return array{0: string, 1: array, 2: array} text, actions, navigation
     */
    private function applyNavigation(string $answer, ?array $rawNavigation, AiAssistantConfig $config): array
    {
        $text = preg_replace(self::ACTION_TOKEN_PATTERN, '', $answer) ?? $answer;
        $navigation = $this->resolveNavigation($rawNavigation, $config);
        $token = $navigation ? '[[ACTION:navigate_' . $navigation['route_key'] . ']]' : null;

        $pattern = '/^([ \t]*(?:(?:[-*\x{2022}]|\d+[.)])[ \t]+)?)\**' . preg_quote(self::CTA_LABEL, '/')
            . '\**(?:[ \t]*(?:->|\x{2192}|\x{2014}|\x{2013}|-|:)[ \t]*(\S.*?))?[ \t]*$/imu';

        $count = 0;
        $text = preg_replace_callback($pattern, function (array $m) use ($token, $navigation) {
            $rest = trim($m[2] ?? '');

            // "**Take Me There — Funding Application**": the bold that wrapped
            // the whole line leaves its closing half on what follows the label.
            if (substr_count($rest, '**') % 2 === 1) {
                $rest = trim(preg_replace('/\*\*$/', '', $rest) ?? $rest);
            }

            if ($navigation && $this->namesDestination($rest, $navigation['label'])) {
                $rest = '';
            }

            if ($token === null) {
                return $rest === '' ? '' : $m[1] . $rest;
            }

            return $m[1] . $token . ($rest === '' ? '' : "\n" . $rest);
        }, $text, -1, $count) ?? $text;

        if ($navigation === null) {
            // The label mentioned in passing ("Select Take Me There, then choose
            // Resources.") points at a button just as much as the line does, so
            // without a destination that sentence goes too.
            $text = preg_replace(
                '/[^.!?\n]*' . preg_quote(self::CTA_LABEL, '/') . '[^.!?\n]*[.!?]*/iu',
                '',
                $text,
                -1,
                $mentions
            ) ?? $text;
            $count += (int) $mentions;
        }

        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);

        if ($navigation === null) {
            if ($count > 0) {
                Log::warning('Take Me There removed: no approved navigation destination was returned', [
                    'assistant_type' => $config->assistant_type,
                ]);
            }

            return [$text, [], $this->noNavigation()];
        }

        if ($count === 0) {
            $text = rtrim($text) . "\n\n" . $token;
        }

        return [
            $text,
            [[
                'type' => 'navigate',
                'id' => 'navigate_' . $navigation['route_key'],
                'label' => self::CTA_LABEL,
                'path' => $navigation['path'],
                'route_key' => $navigation['route_key'],
            ]],
            ['required' => true, 'intent' => self::NAVIGATION_INTENT, 'route_key' => $navigation['route_key']],
        ];
    }

    /**
     * Whether what followed the label is just the destination's name — in
     * full ("Intake & Review > Funding Application") or its last part
     * ("Funding Application").
     */
    private function namesDestination(string $rest, string $label): bool
    {
        $normalise = static fn (string $value) => mb_strtolower(trim(str_replace('*', '', $value), " \t.:"));
        $rest = $normalise($rest);

        if ($rest === '') {
            return false;
        }

        $parts = explode('>', $label);

        return $rest === $normalise($label) || $rest === $normalise((string) end($parts));
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
        // messages() is already ordered oldest-first, and a second orderBy only
        // breaks ties — so without reorder() the limit keeps the FIRST turns
        // of the thread and drops the message the user has just sent.
        return $conversation->messages()
            ->reorder('id', 'desc')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->sortBy('id')
            // A stored reply holds the button's placeholder; the agent is shown
            // its own wording instead, so it never learns to write the token.
            ->map(fn (ChatMessage $m) => [
                'role' => $m->role,
                'content' => OpenAiAgentsService::withoutCitationMarkers(
                    preg_replace(self::ACTION_TOKEN_PATTERN, '**' . self::CTA_LABEL . '**', $m->content) ?? $m->content
                ),
            ])
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
