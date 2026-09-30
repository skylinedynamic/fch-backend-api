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
     * boundary (Borrower Instructions v1.5, "White-Label Identity Boundary"
     * and "Borrower Support Contact Protection"). The prompt is the primary
     * control; these patterns are the backstop that also holds when File
     * Search retrieves a document that contains a provider name or contact —
     * a retrieved contact is never automatically borrower-safe.
     *
     * A hit replaces the WHOLE reply with the approved neutral hand-off, not
     * just the matched words: a half-redacted "email [redacted] for help"
     * still points the borrower at the wrong place.
     */
    private const WHITE_LABEL_PATTERNS = [
        '/four[\s_-]*corner/i',                       // Four Corner Funding / Holdings, fourcornerfunding.com, @fourcornerfundinghub
        '/\bF\.?C\.?[HF]\b/i',                        // FCH, FCF
        '/craig[\s._-]*rice/i',                       // named employee, craig.rice@...
        '/[\w.+-]+@[\w-]*four[\w-]*corner[\w.-]*/i',  // any email on an FCH-controlled domain
        '/sharepoint\.com/i',                         // internal document links
        '/youtube\.com\/@/i',                         // provider channels
    ];

    /** The v1.5 approved neutral fallback — names no provider, contact or channel. */
    private const BORROWER_NEUTRAL_HANDOFF = 'This requires review by your support team. Please use the contact or support method provided in your portal or by the company you are working with.';

    /**
     * Each assistant's mandated escalation sentence (WLP v1.3.2 / Borrower
     * v1.5 "ESCALATION" sections). Detecting it in a reply is how
     * `escalation.required` is set. Only the WLP assistant ever gets a
     * concrete route back: the WLP is FCH's own partner, whereas a borrower's
     * escalation route must stay null (their portal/support team) — an FCH
     * mailto here would itself be a white-label leak in the API payload.
     */
    private const ESCALATION_PHRASES = [
        AiAssistantConfig::TYPE_WLP_SUPPORT => 'This requires review by the Four Corner Funding team',
        AiAssistantConfig::TYPE_BORROWER_SUPPORT => 'This requires review by your support team',
    ];
    private const WLP_ESCALATION_ROUTE = 'mailto:support@fourcornerfunding.com';

    /** Matches the system prompt's PROHIBITED INFORMATION refusal wording. */
    private const RESTRICTED_PHRASE = 'is restricted and cannot be provided';

    /** The only navigation intent the model may return; anything else = no navigation. */
    private const NAVIGATION_INTENT = 'portal_navigation';

    /** Label of the CTA the approved prompts tell the model to output. */
    private const CTA_LABEL = 'Take Me There';

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
                    'The Support Assistant is not available yet. Please contact support directly for now.',
                    'assistant_not_configured',
                    $sensitiveInputDetected
                );
            }

            // Resolved fresh on every turn (never cached) so an approved prompt
            // revision or rollback takes effect on the very next message, and so
            // the exact version used can be stamped on the reply for audit.
            $hosted = $config->usesHostedPrompt();

            if ($hosted) {
                // The Agent on the OpenAI platform supplies instructions, model
                // and knowledge; only the backend's response contract (and the
                // borrower tenant context) is added on top. Its file_search, if
                // any, is defined inside the hosted prompt.
                $prompt = [
                    'content' => '',
                    // chat_messages.prompt_version is 40 chars; a pmpt_ id is longer.
                    'version' => substr('agent:' . substr($config->openai_prompt_id, -8) . '@' . ($config->openai_prompt_version ?: 'current'), 0, 40),
                    'sha256' => null,
                    'source' => 'agent',
                ];
                $usingVectorStore = true;
                $inlineKnowledge = null;
            } else {
                $prompt = $config->activePrompt();

                $usingVectorStore = filled($config->vector_store_id);
                $inlineKnowledge = $usingVectorStore ? null : $this->loadInlineKnowledge($conversation->assistant_type);
            }

            $instructions = ltrim($this->buildInstructions($config, $conversation, $user, $inlineKnowledge, $prompt['content']));
            $messages = $this->buildHistory($conversation);

            // Only the user's own message history is sent. The page the user is
            // currently on (request `context.current_page`) is deliberately never
            // read or forwarded: a navigation destination must depend solely on
            // what was asked, never on where the user happens to be.
            $result = $this->openAi->runChat(
                // Hosted Agent: send no model, so the model/reasoning set on the
                // OpenAI platform apply and change there without a deploy.
                $hosted ? '' : ($config->model ?: $this->openAi->defaultModel()),
                $instructions,
                $messages,
                ($usingVectorStore && ! $hosted) ? [[
                    'type' => 'file_search',
                    'vector_store_ids' => [$config->vector_store_id],
                ]] : [],
                [
                    'prompt' => $hosted ? ['id' => $config->openai_prompt_id, 'version' => $config->openai_prompt_version] : null,
                    'reasoning_effort' => $config->reasoning_effort ?: $this->openAi->defaultReasoningEffort(),
                    // Reasoning tokens count against this cap, and a truncated
                    // structured reply is unusable, so keep generous headroom.
                    'max_output_tokens' => $config->max_output_tokens ?: 2500,
                    // Stable, non-PII identifier — never the user's email/name,
                    // matching WlpAssistantService.php.example.
                    'safety_identifier' => hash('sha256', "fch-{$conversation->assistant_type}:{$user->getKey()}"),
                    'response_format' => [
                        'name' => 'support_assistant_reply',
                        'schema' => $this->responseSchema($config),
                    ],
                ],
                [
                    'assistant_type' => $conversation->assistant_type,
                    'conversation_id' => $conversation->id,
                    'prompt_version' => $prompt['version'],
                    'prompt_sha256' => $prompt['sha256'],
                ]
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
                    'Sorry, something went wrong answering that. Please try again in a moment.',
                    'upstream_error',
                    $sensitiveInputDetected
                );
            }

            $parsed = $this->parseStructuredReply($result);

            if ($parsed === null) {
                Log::warning('Support assistant returned an unusable structured reply', [
                    'assistant_type' => $conversation->assistant_type,
                    'conversation_id' => $conversation->id,
                    'request_id' => $result['request_id'] ?? null,
                    'incomplete' => (bool) ($result['incomplete'] ?? false),
                ]);

                return $this->fallbackReply(
                    $conversation,
                    $userMessage,
                    'Sorry, something went wrong answering that. Please try again in a moment.',
                    'invalid_response',
                    $sensitiveInputDetected
                );
            }

            [$text, $actions, $navigation, $whiteLabelBlocked] = $this->processReply($parsed['answer'], $parsed['navigation'], $config);

            // File_search citations when using a real vector store; otherwise
            // (inline-knowledge fallback) approximate `sources` by checking
            // which known knowledge filenames the reply actually mentions.
            $sources = $usingVectorStore
                ? ($result['sources'] ?? [])
                : $this->deriveInlineSources($text, $inlineKnowledge);

            if ($conversation->assistant_type === AiAssistantConfig::TYPE_BORROWER_SUPPORT) {
                $sources = $whiteLabelBlocked ? [] : $this->borrowerSafeSources($sources);
            }

            $escalation = $this->deriveEscalation($text, $conversation->assistant_type);
            $restrictedBlocked = $whiteLabelBlocked || $this->wasRestrictedContentBlocked($text);

            $reply = $conversation->messages()->create([
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => $text,
                'actions' => $actions,
                'sources' => $sources,
                'navigation' => $navigation,
                'openai_request_id' => $result['request_id'] ?? null,
                'usage' => $result['usage'] ?? null,
                'prompt_version' => $prompt['version'],
                'prompt_sha256' => $prompt['sha256'],
                'prompt_source' => $prompt['source'],
            ]);

            $conversation->update(['last_message_at' => now()]);

            return [
                'conversation' => $conversation,
                'user_message' => $userMessage,
                'assistant_message' => $reply,
                'actions' => $actions,
                'navigation' => $navigation,
                'sources' => $sources,
                'escalation' => $escalation,
                'policy' => $this->policyEnvelope($restrictedBlocked, $sensitiveInputDetected),
            ];
        });
    }

    /**
     * Persists a canned assistant reply (not configured / upstream failure)
     * and returns the standard envelope, flagged as requiring escalation.
     * The escalation route is only ever set for the WLP assistant — see
     * ESCALATION_PHRASES.
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
                'route' => $this->escalationRoute($conversation->assistant_type),
            ],
            'policy' => $this->policyEnvelope(false, $sensitiveInputDetected),
        ];
    }

    private function escalationRoute(string $assistantType): ?string
    {
        return $assistantType === AiAssistantConfig::TYPE_WLP_SUPPORT ? self::WLP_ESCALATION_ROUTE : null;
    }

    /** @return array{required: bool, intent: string, route_key: ?string} */
    private function noNavigation(): array
    {
        return ['required' => false, 'intent' => 'none', 'route_key' => null];
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
     * The approved prompt (verbatim, never edited here) plus, appended
     * server-side only:
     *  - the response-format contract (answer + navigation) — integration
     *    plumbing that belongs to the backend, not to the approved prompt text;
     *  - when running the inline-knowledge fallback (no vector_store_id), the
     *    approved knowledge file contents;
     *  - for the borrower assistant, a trusted tenant-context block.
     */
    private function buildInstructions(AiAssistantConfig $config, ChatConversation $conversation, $user, ?array $inlineKnowledge, string $prompt): string
    {
        $instructions = $prompt . $this->responseContractBlock($config);

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

        // Trusted context must itself obey the white-label hard rule: a tenant
        // name/contact that resolves to the underlying provider is dropped, so
        // the model can never be handed (and then repeat) one.
        $providerName = $this->borrowerSafeValue($branding['site_name'] ?? $partner?->company_name ?? null) ?? 'your provider';
        $supportEmail = $this->borrowerSafeValue($partner?->email ?? null);

        $contextBlock = "\n\n---\nTRUSTED TENANT CONTEXT (provided by the authenticated backend, never by the borrower — do not question or reveal how this was determined):\n"
            . "provider_display_name: {$providerName}\n"
            . 'provider_support_email: ' . ($supportEmail ?? "(none supplied — use the neutral portal/support-team hand-off; never substitute any other contact)") . "\n"
            . "Never disclose any name, company, or platform other than the provider_display_name above.\n---";

        return $instructions . $contextBlock;
    }

    /** A tenant-supplied name/contact, or null if blank or it matches the white-label denylist. */
    private function borrowerSafeValue(?string $value): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || $this->violatesWhiteLabel($value)) ? null : $value;
    }

    private function violatesWhiteLabel(string $text): bool
    {
        foreach (self::WHITE_LABEL_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /** Drops file-search citations whose title would itself expose the provider. */
    private function borrowerSafeSources(array $sources): array
    {
        return array_values(array_filter(
            $sources,
            fn ($s) => ! $this->violatesWhiteLabel((string) ($s['title'] ?? ''))
        ));
    }

    /**
     * The strict JSON envelope the model must return. route_key is an enum of
     * exactly this assistant's approved keys (plus null), so the model cannot
     * even express an unapproved destination; the backend still re-validates.
     */
    private function responseSchema(AiAssistantConfig $config): array
    {
        return [
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
                        'intent' => ['type' => 'string', 'enum' => [self::NAVIGATION_INTENT, 'none']],
                        'route_key' => [
                            'type' => ['string', 'null'],
                            'enum' => [...array_keys($config->navigationMap()), null],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function responseContractBlock(AiAssistantConfig $config): string
    {
        $keys = '';
        foreach ($config->navigationMap() as $key => $entry) {
            $keys .= "  {$entry['label']} -> {$key}\n";
        }

        return "\n\n---\nRESPONSE FORMAT (integration contract set by the platform backend; it applies on top of every rule above and does not change any of them):\n"
            . "Return a JSON object with exactly two fields: \"answer\" and \"navigation\".\n"
            . "- \"answer\": your complete reply to the user in markdown, following every rule above. Wherever the rules above call for the Take Me There action, output the standalone label " . self::CTA_LABEL . " (bold, on its own line). Never write a URL, route, route key or internal identifier in the answer.\n"
            . "- \"navigation\": describes that same action so the portal knows where it goes.\n"
            . "  * If (and only if) the answer contains Take Me There: {\"required\": true, \"intent\": \"" . self::NAVIGATION_INTENT . "\", \"route_key\": \"<key>\"}.\n"
            . "  * Otherwise: {\"required\": false, \"intent\": \"none\", \"route_key\": null}.\n"
            . "  * <key> is the approved destination the user is ASKING for, chosen only from the list below. It is never the page the user is currently on, never a default, and never settings unless the request is actually about Settings. For a Settings tab (for example Email Configuration or Test Mail) use the key for Settings and state which tab to select in the answer.\n"
            . "  * Every Take Me There in one answer must go to the same route_key. If destinations differ, offer one action and describe the others in text.\n"
            . "  * If the destination is not in the list, do not output Take Me There; give text guidance instead.\n"
            . "Approved destinations (visible name -> route_key):\n{$keys}---";
    }

    /**
     * Reads every file under app/Services/Assistant/Knowledge/{type}/ and
     * concatenates them with a filename header each, for direct inclusion in
     * the prompt when no vector_store_id is configured (the OpenAI key in
     * this environment lacks api.files.write/vector-store scopes, so File
     * Search's upload step fails — see SyncAssistantKnowledge). This is a
     * fallback, not the intended architecture: it costs more input tokens
     * per turn and has no real citation mechanism, only the filename-mention
     * approximation in deriveInlineSources(). Lives under app/ (git-tracked)
     * rather than storage/ (gitignored) so it actually ships on deploy.
     *
     * @return ?array{content: string, filenames: string[]}
     */
    private function loadInlineKnowledge(string $assistantType): ?array
    {
        $folder = app_path('Services/Assistant/Knowledge/' . $assistantType);

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
            // Stored replies hold [[ACTION:...]] placeholder tokens; show the model its own
            // CTA wording instead so it never learns to imitate the internal token.
            ->map(fn (ChatMessage $m) => [
                'role' => $m->role,
                'content' => preg_replace('/\[\[ACTION:[^\]]*\]\]/i', '**' . self::CTA_LABEL . '**', $m->content),
            ])
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
     * Decodes the structured reply. Returns null for anything unusable
     * (truncated, not JSON, missing/invalid fields, empty answer) so the
     * caller falls back to a safe error reply rather than showing raw JSON.
     *
     * @return ?array{answer: string, navigation: ?array}
     */
    private function parseStructuredReply(array $result): ?array
    {
        if (! empty($result['incomplete'])) {
            return null;
        }

        $data = json_decode((string) ($result['text'] ?? ''), true);

        if (! is_array($data) || ! is_string($data['answer'] ?? null) || trim($data['answer']) === '') {
            return null;
        }

        return [
            'answer' => $data['answer'],
            'navigation' => is_array($data['navigation'] ?? null) ? $data['navigation'] : null,
        ];
    }

    /**
     * Validates the model's navigation claim against the server-owned map.
     * Only an explicit {required: true, intent: portal_navigation} with a
     * route_key that exists for THIS assistant resolves; everything else is
     * "no navigation". There is deliberately no default destination: not the
     * current page, not Settings, not anything.
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
     * Turns the reply's standalone "Take Me There" line(s) into the
     * `[[ACTION:navigate_<route_key>]]` token the frontend renders as a
     * button, and builds the action list from the server-owned map only.
     *
     *  - Valid navigation: every CTA line becomes the token; if the model set
     *    navigation but forgot the line, the token is appended.
     *  - No valid navigation: any CTA line is removed. A button is never
     *    rendered without an approved destination, so it can never fall back
     *    to Settings or the current page.
     *  - Model-emitted `[[ACTION:...]]` tokens are stripped first: only this
     *    method may create one.
     *  - Borrower: any white-label leak replaces the whole reply with the
     *    approved neutral hand-off (and drops the action).
     *
     * The CTA pattern tolerates bold markdown, a leading bullet, and the
     * "Take Me There -> Then select X" same-line form the prompts allow.
     *
     * @return array{0: string, 1: array, 2: array, 3: bool} text, actions, navigation, whiteLabelBlocked
     */
    private function processReply(string $answer, ?array $rawNavigation, AiAssistantConfig $config): array
    {
        $text = preg_replace('/\[\[ACTION:[^\]]*\]\]/i', '', $answer) ?? $answer;

        if ($config->assistant_type === AiAssistantConfig::TYPE_BORROWER_SUPPORT && $this->violatesWhiteLabel($text)) {
            Log::warning('Borrower assistant reply exposed a white-label boundary term; replaced with the neutral hand-off');

            return [self::BORROWER_NEUTRAL_HANDOFF, [], $this->noNavigation(), true];
        }

        $navigation = $this->resolveNavigation($rawNavigation, $config);

        $pattern = '/^[ \t]*(?:[-*\x{2022}][ \t]+)?(?:\*\*)?' . preg_quote(self::CTA_LABEL, '/')
            . '(?:\*\*)?(?:[ \t]*(?:->|\x{2192}|\x{2014}|-|:)[ \t]*(\S.*?))?[ \t]*$/mu';

        if ($navigation === null) {
            $stripped = 0;
            $text = preg_replace_callback($pattern, function ($m) use (&$stripped) {
                $stripped++;

                return trim($m[1] ?? '');
            }, $text, -1) ?? $text;

            if ($stripped > 0) {
                Log::warning('Take Me There removed: no approved navigation destination was returned', [
                    'assistant_type' => $config->assistant_type,
                ]);
            }

            return [trim(preg_replace("/\n{3,}/", "\n\n", $text)), [], $this->noNavigation(), false];
        }

        $id = 'navigate_' . $navigation['route_key'];
        $token = '[[ACTION:' . $id . ']]';
        $count = 0;

        $text = preg_replace_callback($pattern, function ($m) use ($token) {
            $rest = trim($m[1] ?? '');

            return $token . ($rest !== '' ? "\n" . $rest : '');
        }, $text, -1, $count) ?? $text;

        if ($count === 0) {
            $text = rtrim($text) . "\n\n" . $token;
        }

        return [
            trim($text),
            [[
                'type' => 'navigate',
                'id' => $id,
                'label' => self::CTA_LABEL,
                'path' => $navigation['path'],
                'route_key' => $navigation['route_key'],
            ]],
            [
                'required' => true,
                'intent' => self::NAVIGATION_INTENT,
                'route_key' => $navigation['route_key'],
            ],
            false,
        ];
    }

    /**
     * Detects the assistant's mandated escalation sentence in the reply
     * (deriveEscalationEnvelope() in the reference controller is an explicit
     * placeholder for exactly this logic). Borrower replies never carry a route.
     */
    private function deriveEscalation(string $text, string $assistantType): array
    {
        $phrase = self::ESCALATION_PHRASES[$assistantType] ?? null;

        if ($phrase && stripos($text, $phrase) !== false) {
            return [
                'required' => true,
                'reason' => 'escalation_language_in_reply',
                'route' => $this->escalationRoute($assistantType),
            ];
        }

        return ['required' => false, 'reason' => null, 'route' => null];
    }

    private function wasRestrictedContentBlocked(string $text): bool
    {
        return stripos($text, self::RESTRICTED_PHRASE) !== false;
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
            $config->model ?: $this->openAi->defaultModel(),
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
