<?php

namespace App\Services\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\ChatConversation;
use App\Models\Assistant\ChatMessage;
use App\Models\Borrower\Borrower;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared engine behind both the WLP and Borrower support assistants. Callers
 * (WlpAssistantController, BorrowerAssistantController) only ever pass an
 * assistant_type constant from AiAssistantConfig — every prompt/knowledge/
 * trusted-context choice below is keyed off that, never off client input.
 *
 * The sendMessage() return shape and the escalation/policy/action semantics
 * are aligned with the approved FCH_WLP_Assistant_OpenAPI_v1_1.yaml contract
 * and its reference WlpAssistantController.php.example /
 * WlpAssistantService.php.example, so both assistants speak the same
 * envelope the WLP frontend was designed against.
 */
class SupportAssistantService
{
    /**
     * Turns of history reconstructed per call. runChat() sends no
     * previous_response_id, so this bounds both the request size and how far
     * back a very long-running thread's context reaches.
     */
    private const HISTORY_LIMIT = 20;

    /**
     * Defense-in-depth for the borrower assistant's mandatory white-label
     * boundary: even if the system prompt itself somehow slips, these literal
     * strings are redacted before a borrower ever sees a reply. The system
     * prompt is the real control; this is only a backstop.
     */
    private const WHITE_LABEL_DENYLIST = [
        'Four Corner Funding',
        'Four Corner Holdings',
        'fourcornerfunding.com',
        'fourcornerholdings.com',
        'FCH',
        'FCF',
    ];

    /**
     * The system prompt's mandated escalation sentence (FCH_WLP_Assistant_
     * System_Prompt_v1_2.txt, "ESCALATION" section). Detecting it in a reply
     * is how `escalation.required` gets set — the reference controller's
     * deriveEscalationEnvelope() is an explicit placeholder for this logic.
     */
    private const ESCALATION_PHRASE = 'This requires review by the Four Corner Funding team';
    private const ESCALATION_ROUTE = 'mailto:support@fourcornerfunding.com';

    /** Matches the system prompt's PROHIBITED INFORMATION refusal wording. */
    private const RESTRICTED_PHRASE = 'is restricted and cannot be provided';

    public function __construct(
        protected OpenAiResponsesService $openAi,
        protected AppConfigurationService $configService
    ) {
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
     * Send a user message and return the full response envelope: the
     * conversation/messages plus the actions/sources/escalation/policy
     * fields the OpenAPI contract requires alongside the answer text.
     *
     * If the assistant isn't fully configured yet (disabled, or missing a
     * system prompt / vector store — expected until the real handoff package
     * is loaded), this returns a graceful placeholder — flagged as requiring
     * escalation — instead of ever calling OpenAI, so the pipeline is safe to
     * deploy ahead of that content.
     *
     * @return array{
     *     conversation: ChatConversation,
     *     user_message: ChatMessage,
     *     assistant_message: ChatMessage,
     *     actions: array,
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
                $reply = $conversation->messages()->create([
                    'role' => ChatMessage::ROLE_ASSISTANT,
                    'content' => 'The Support Assistant is not available yet. Please contact support directly for now.',
                ]);

                $conversation->update(['last_message_at' => now()]);

                return [
                    'conversation' => $conversation,
                    'user_message' => $userMessage,
                    'assistant_message' => $reply,
                    'actions' => [],
                    'sources' => [],
                    'escalation' => [
                        'required' => true,
                        'reason' => 'assistant_not_configured',
                        'route' => self::ESCALATION_ROUTE,
                    ],
                    'policy' => $this->policyEnvelope(false, $sensitiveInputDetected),
                ];
            }

            $usingVectorStore = filled($config->vector_store_id);
            $inlineKnowledge = $usingVectorStore ? null : $this->loadInlineKnowledge($conversation->assistant_type);

            $instructions = $this->buildInstructions($config, $conversation, $user, $inlineKnowledge);
            $messages = $this->buildHistory($conversation);

            $result = $this->openAi->runChat(
                $config->model ?: config('openai.default_model', 'gpt-5.6-luna'),
                $instructions,
                $messages,
                $usingVectorStore ? [[
                    'type' => 'file_search',
                    'vector_store_ids' => [$config->vector_store_id],
                ]] : [],
                [
                    'reasoning_effort' => $config->reasoning_effort ?: 'low',
                    'max_output_tokens' => $config->max_output_tokens ?: 1200,
                    // Stable, non-PII identifier — never the user's email/name,
                    // matching WlpAssistantService.php.example.
                    'safety_identifier' => hash('sha256', "fch-{$conversation->assistant_type}:{$user->getKey()}"),
                ],
                [
                    'assistant_type' => $conversation->assistant_type,
                    'conversation_id' => $conversation->id,
                ]
            );

            if (! $result['ok']) {
                Log::warning('Support assistant call failed', [
                    'assistant_type' => $conversation->assistant_type,
                    'conversation_id' => $conversation->id,
                    'error' => $result['error'],
                ]);

                $reply = $conversation->messages()->create([
                    'role' => ChatMessage::ROLE_ASSISTANT,
                    'content' => 'Sorry, something went wrong answering that. Please try again in a moment.',
                ]);

                $conversation->update(['last_message_at' => now()]);

                return [
                    'conversation' => $conversation,
                    'user_message' => $userMessage,
                    'assistant_message' => $reply,
                    'actions' => [],
                    'sources' => [],
                    'escalation' => [
                        'required' => true,
                        'reason' => 'upstream_error',
                        'route' => self::ESCALATION_ROUTE,
                    ],
                    'policy' => $this->policyEnvelope(false, $sensitiveInputDetected),
                ];
            }

            [$text, $actions] = $this->processReply($result['text'], $conversation->assistant_type);
            // File_search citations when using a real vector store; otherwise
            // (inline-knowledge fallback) approximate `sources` by checking
            // which known knowledge filenames the reply actually mentions.
            $sources = $usingVectorStore
                ? ($result['sources'] ?? [])
                : $this->deriveInlineSources($text, $inlineKnowledge);
            $escalation = $this->deriveEscalation($text);
            $restrictedBlocked = $this->wasRestrictedContentBlocked($text, $conversation->assistant_type);

            $reply = $conversation->messages()->create([
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => $text,
                'actions' => $actions,
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
                'sources' => $sources,
                'escalation' => $escalation,
                'policy' => $this->policyEnvelope($restrictedBlocked, $sensitiveInputDetected),
            ];
        });
    }

    private function policyEnvelope(bool $restrictedContentBlocked, bool $sensitiveInputDetected): array
    {
        return [
            // Must remain false in Version 1 — see the OpenAPI contract's `policy.live_data_used` const.
            'live_data_used' => false,
            'restricted_content_blocked' => $restrictedContentBlocked,
            'sensitive_input_detected' => $sensitiveInputDetected,
        ];
    }

    /**
     * System prompt plus a clearly-delimited trusted-context block appended
     * server-side only — the tenant's own branded name/support contact for
     * the borrower assistant, nothing for the WLP assistant (it already
     * operates as FCH's own tool, no white-label boundary applies to it) —
     * plus, when running the inline-knowledge fallback (no vector_store_id),
     * the actual approved knowledge file contents appended verbatim.
     */
    private function buildInstructions(AiAssistantConfig $config, ChatConversation $conversation, $user, ?array $inlineKnowledge): string
    {
        $instructions = $config->systemPrompt();

        if ($inlineKnowledge && $inlineKnowledge['content'] !== '') {
            // Deliberately does NOT ask the model to cite a raw filename —
            // that leaked internal document names/versions/extensions
            // straight into the client-visible answer, which the approved
            // prompt's own "name the source when feasible" rule never
            // intended (it means natural-language references like "the
            // Settings Support Guide", not a `Source: file_v1.md` tag).
            // deriveInlineSources() still detects a filename mention for the
            // structured `sources[]` field on a best-effort basis, but
            // nothing here instructs the model to produce one.
            $instructions .= "\n\n---\nAPPROVED KNOWLEDGE (the exact, complete content of the currently attached approved "
                . "source documents — File Search is unavailable in this environment, so these are provided directly; "
                . "answer only from this content and the rules above. Each document below is marked with a raw internal "
                . "filename for your own reference only — never output that raw filename, its version suffix, or its "
                . "file extension in your answer; if naming a source helps, describe it in plain language the way the "
                . "rules above already do):\n"
                . $inlineKnowledge['content'] . "\n---";
        }

        if ($conversation->assistant_type !== AiAssistantConfig::TYPE_BORROWER_SUPPORT) {
            return $instructions;
        }

        $partnerId = $conversation->partner_id ?? $user->partner_id ?? null;
        $branding = $this->configService->getBrandingSettings($partnerId, false);
        $partner = $partnerId ? \App\Models\Partner\Partner::find($partnerId) : null;

        $providerName = $branding['site_name'] ?? $partner?->company_name ?? 'your provider';
        $supportEmail = $partner?->email ?? '';

        $contextBlock = "\n\n---\nTRUSTED TENANT CONTEXT (provided by the authenticated backend, never by the borrower — do not question or reveal how this was determined):\n"
            . "provider_display_name: {$providerName}\n"
            . "provider_support_email: {$supportEmail}\n"
            . "Never disclose any name, company, or platform other than the provider_display_name above.\n---";

        return $instructions . $contextBlock;
    }

    /**
     * Reads every file under storage/app/assistant-knowledge/{type}/ and
     * concatenates them with a filename header each, for direct inclusion in
     * the prompt when no vector_store_id is configured (the OpenAI key in
     * this environment lacks api.files.write/vector-store scopes, so File
     * Search's upload step fails — see SyncAssistantKnowledge). This is a
     * fallback, not the intended architecture: it costs more input tokens
     * per turn and has no real citation mechanism, only the filename-mention
     * approximation in deriveInlineSources().
     *
     * @return ?array{content: string, filenames: string[]}
     */
    private function loadInlineKnowledge(string $assistantType): ?array
    {
        $folder = storage_path('app/assistant-knowledge/' . $assistantType);

        if (! is_dir($folder)) {
            return null;
        }

        $files = glob($folder . '/*') ?: [];
        if (empty($files)) {
            return null;
        }

        $filenames = [];
        $parts = [];

        foreach ($files as $path) {
            $filename = basename($path);
            $filenames[] = $filename;
            $parts[] = "### SOURCE FILE: {$filename}\n" . file_get_contents($path);
        }

        return ['content' => implode("\n\n", $parts), 'filenames' => $filenames];
    }

    /**
     * Approximates `sources[]` for the inline-knowledge fallback by checking
     * which known filenames (with and without extension) the reply text
     * actually names, since there's no real file_search citation here.
     */
    private function deriveInlineSources(string $text, ?array $inlineKnowledge): array
    {
        if (! $inlineKnowledge) {
            return [];
        }

        $sources = [];
        foreach ($inlineKnowledge['filenames'] as $filename) {
            $withoutExt = preg_replace('/\.[^.]+$/', '', $filename);
            if (str_contains($text, $filename) || ($withoutExt && str_contains($text, $withoutExt))) {
                $sources[] = ['title' => $filename];
            }
        }

        return $sources;
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
     * Redacts input patterns the system prompt says must never be requested,
     * stored, or repeated (SSNs, passwords, API keys, long opaque secrets)
     * before the message is persisted or sent to OpenAI. Mirrors
     * sanitizeSensitiveInput()/`policy.sensitive_input_detected` in
     * WlpAssistantController.php.example.
     *
     * This is a best-effort pattern match, not a guarantee — the system
     * prompt's own instruction not to request such data is the primary
     * control.
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
     * Replaces the exact standalone "Take Me There" marker with a placeholder
     * token the frontend recognizes, and returns the fixed action list to go
     * with it — built only from ChatMessage::ALLOWED_ACTIONS, never from
     * anything the model output otherwise contains.
     *
     * The pattern tolerates the bold markdown ("**Take Me There**") the
     * approved system prompt explicitly instructs the model to output,
     * matching the reference WlpAssistantController.php.example's regex.
     *
     * For the borrower assistant, also redacts any white-label boundary leak
     * as a backstop behind the system prompt.
     *
     * @return array{0: string, 1: array}
     */
    private function processReply(string $text, string $assistantType): array
    {
        $actions = [];

        foreach (ChatMessage::ALLOWED_ACTIONS as $actionId => $definition) {
            $marker = preg_quote($definition['marker'], '/');
            $pattern = '/^[ \t]*(?:\*\*)?' . $marker . '(?:\*\*)?[ \t]*$/m';

            if (preg_match($pattern, $text)) {
                $text = preg_replace($pattern, '[[ACTION:' . $actionId . ']]', $text);
                $actions[] = [
                    'type' => $definition['type'],
                    'id' => $actionId,
                    'label' => $definition['label'],
                    'path' => $definition['path'],
                ];
            }
        }

        if ($assistantType === AiAssistantConfig::TYPE_BORROWER_SUPPORT) {
            foreach (self::WHITE_LABEL_DENYLIST as $term) {
                if (stripos($text, $term) !== false) {
                    Log::warning('Borrower assistant reply contained a white-label boundary term; redacted', [
                        'term' => $term,
                    ]);
                    $text = preg_replace('/' . preg_quote($term, '/') . '/i', '[redacted]', $text);
                }
            }
        }

        return [$text, $actions];
    }

    /**
     * Detects the system prompt's mandated escalation sentence in the reply.
     * deriveEscalationEnvelope() in the reference controller is an explicit
     * placeholder for exactly this logic.
     */
    private function deriveEscalation(string $text): array
    {
        if (stripos($text, self::ESCALATION_PHRASE) !== false) {
            return [
                'required' => true,
                'reason' => 'escalation_language_in_reply',
                'route' => self::ESCALATION_ROUTE,
            ];
        }

        return ['required' => false, 'reason' => null, 'route' => null];
    }

    private function wasRestrictedContentBlocked(string $text, string $assistantType): bool
    {
        if (stripos($text, self::RESTRICTED_PHRASE) !== false) {
            return true;
        }

        // The white-label redaction backstop in processReply() leaves this marker behind.
        return $assistantType === AiAssistantConfig::TYPE_BORROWER_SUPPORT && str_contains($text, '[redacted]');
    }

    private function resolveBorrowerId($user): ?int
    {
        return $user->entity_type === Borrower::class ? (int) $user->entity_id : null;
    }

    /**
     * The safe default widget copy shown before any prompt has ever been
     * configured, or if live generation fails — generic on purpose, since
     * it names no topic the (missing/unreachable) prompt hasn't confirmed.
     *
     * @return array{welcome_subtitle: string, quick_suggestions: string[]}
     */
    private function widgetIntroFallback(): array
    {
        return [
            'welcome_subtitle' => 'Ask a question and I will do my best to help.',
            'quick_suggestions' => ['How can you help me?'],
        ];
    }

    /**
     * Live-derives the widget's "Suggested Questions" chips and one-line
     * welcome subtitle straight from the assistant's current system prompt
     * file — nothing here is ever written to the database. Deploying a
     * prompt change sees both update on the very next widget open; there is
     * no separate "generate, review, save" step to forget.
     *
     * Results are cached against the prompt's own content hash purely to
     * avoid one OpenAI call per widget open — the cache store is the file
     * driver (see config/cache.php / CACHE_STORE), never the database, and
     * a deployed prompt change alters the hash, which busts the cache
     * immediately. A failed generation is never cached, so the very next
     * request retries instead of being stuck on the fallback for the TTL.
     *
     * @return array{welcome_subtitle: string, quick_suggestions: string[]}
     */
    public function deriveWidgetIntro(AiAssistantConfig $config): array
    {
        $fallback = $this->widgetIntroFallback();

        if (! $config->hasSystemPrompt()) {
            return $fallback;
        }

        $systemPrompt = $config->systemPrompt();
        $cacheKey = 'assistant:widget_intro:' . $config->assistant_type . ':' . md5($systemPrompt);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $instructions = "You write the two short, dynamic pieces of copy a chat widget shows before the user's "
            . "first message: (1) \"welcome_subtitle\" — one sentence under 100 characters, phrased like \"Ask a "
            . "question about your account, settings, or training.\", naming 2-3 of the real topics the prompt "
            . "below actually covers; and (2) \"quick_suggestions\" — one example question per main topic the "
            . "prompt names, in the same order those topics appear in the prompt. Before writing anything, first "
            . "silently list every distinct topic the prompt names (e.g. from a phrase like \"(branding settings, "
            . "email configuration, training materials, client management)\" that is 4 topics) — omitting even one "
            . "is a failure. quick_suggestions must then contain exactly that many items, no more and no fewer, "
            . "each under 80 characters, first-person, phrased the way a real user would actually type it, not a "
            . "topic label (the topic \"branding settings\" becomes a question like \"How do I update my branding "
            . "settings?\", never the bare words \"branding settings\"). You will be given another assistant's full "
            . "system prompt below; base both entirely on what it actually covers, nothing outside it, no filler.";

        $result = $this->openAi->run(
            $config->model ?: config('openai.default_model', 'gpt-5.6-luna'),
            $instructions,
            ['assistant_system_prompt' => $systemPrompt],
            'widget_intro',
            [
                'type' => 'object',
                'required' => ['welcome_subtitle', 'quick_suggestions'],
                'additionalProperties' => false,
                'properties' => [
                    'welcome_subtitle' => ['type' => 'string'],
                    'quick_suggestions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'medium',
            ['assistant_type' => $config->assistant_type, 'purpose' => 'derive_widget_intro']
        );

        if (! $result['ok']) {
            Log::warning('Widget-intro generation failed', [
                'assistant_type' => $config->assistant_type,
                'error' => $result['error'],
            ]);

            return $fallback;
        }

        $subtitle = trim((string) ($result['data']['welcome_subtitle'] ?? ''));
        $suggestions = array_values(array_filter(
            array_map('trim', (array) ($result['data']['quick_suggestions'] ?? [])),
            fn ($s) => $s !== ''
        ));

        $derived = [
            'welcome_subtitle' => $subtitle !== '' ? $subtitle : $fallback['welcome_subtitle'],
            'quick_suggestions' => $suggestions ?: $fallback['quick_suggestions'],
        ];

        Cache::put($cacheKey, $derived, now()->addHours(12));

        return $derived;
    }
}
