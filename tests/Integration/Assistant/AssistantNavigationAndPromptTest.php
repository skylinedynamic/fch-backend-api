<?php

namespace Tests\Integration\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\AiAssistantPromptVersion;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Assistant\AssistantPromptService;
use App\Services\Assistant\SupportAssistantService;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance coverage for the SCRUM-134 / SCRUM-137 production-alignment spec:
 * deterministic Take Me There navigation, Borrower white-label/contact
 * protection, and versioned prompt management. Only the OpenAI HTTP boundary
 * is mocked; the model's reply is simulated as the strict JSON envelope.
 *
 * What this cannot prove is what the live model chooses — that is the staging
 * run. It proves everything the backend does with whatever the model returns.
 */
class AssistantNavigationAndPromptTest extends TestCase
{
    use RefreshDatabase;

    private User $wlpUser;
    private User $borrowerUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Real bundled prompts + bundled inline knowledge: no file writes, no vector store.
        foreach (AiAssistantConfig::TYPES as $type) {
            config(["assistant.{$type}.is_enabled" => true, "assistant.{$type}.model" => 'gpt-test', "assistant.{$type}.reasoning_effort" => 'low']);
        }

        // The host partner is literally the underlying provider: the worst case for
        // the borrower trusted-context block.
        $partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'support@fourcornerfunding.com',
        ]);
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Jo',
            'last_name' => 'Borrower',
            'email' => 'b-' . uniqid() . '@example.test',
        ]);

        $this->wlpUser = $this->makeUser(User::PARTNER, ['partner_id' => $partner->id]);
        $this->borrowerUser = $this->makeUser(User::BORROWER, [
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'partner_id' => $partner->id,
        ]);
    }

    private function makeUser(string $type, array $attrs = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'T', 'email' => 'u-' . uniqid() . '@example.test', 'password' => bcrypt('x'),
            'user_type' => $type, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return User::findOrFail($id);
    }

    private function envelope(string $answer, ?string $routeKey = null, ?string $intent = 'portal_navigation'): string
    {
        return json_encode([
            'answer' => $answer,
            'navigation' => $routeKey === null
                ? ['required' => false, 'intent' => 'none', 'route_key' => null]
                : ['required' => true, 'intent' => $intent, 'route_key' => $routeKey],
        ]);
    }

    /** Sends one message with the model mocked to return $modelText; returns [result, captured runChat args]. */
    private function ask(string $type, string $question, string $modelText, ?array &$captured = null): array
    {
        $captured = [];
        $this->mock(OpenAiResponsesService::class, function ($mock) use ($modelText, &$captured) {
            $mock->shouldReceive('defaultModel')->andReturn('gpt-test');
            $mock->shouldReceive('defaultReasoningEffort')->andReturn('low');
            $mock->shouldReceive('runChat')->once()->andReturnUsing(function (...$args) use ($modelText, &$captured) {
                $captured = $args;

                return ['ok' => true, 'text' => $modelText, 'sources' => [], 'model' => 'gpt-test', 'usage' => null, 'request_id' => 'req_x'];
            });
        });

        $user = $type === AiAssistantConfig::TYPE_WLP_SUPPORT ? $this->wlpUser : $this->borrowerUser;
        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($user, $type);

        return $service->sendMessage($conversation, $question, $user);
    }

    // ---- 1. The approved prompts are what actually gets sent ---------------

    #[Test]
    public function the_bundled_prompts_are_the_approved_versions_and_not_placeholders(): void
    {
        $wlp = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->activePrompt();
        $borrower = AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->activePrompt();

        $this->assertSame('1.3.2', $wlp['version']);
        $this->assertSame('1.5', $borrower['version']);
        $this->assertSame('file', $wlp['source']);
        $this->assertStringContainsString('CANONICAL WLP NAVIGATION MAP', $wlp['content']);
        $this->assertStringContainsString('BORROWER SUPPORT CONTACT PROTECTION', $borrower['content']);
        $this->assertGreaterThan(30000, strlen($wlp['content']));
        $this->assertGreaterThan(30000, strlen($borrower['content']));
    }

    #[Test]
    public function no_bundled_knowledge_file_is_a_test_placeholder_and_borrower_knowledge_holds_no_provider_contact(): void
    {
        foreach (AiAssistantConfig::TYPES as $type) {
            foreach (glob(app_path("Services/Assistant/Knowledge/{$type}/*")) as $path) {
                $this->assertStringNotContainsString('TEST_PLACEHOLDER', basename($path), "placeholder knowledge shipped for {$type}");
            }
        }

        foreach (glob(app_path('Services/Assistant/Knowledge/borrower_support/*')) as $path) {
            $body = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/craig|four\s*corner|fourcorner|\bFCH\b|\bFCF\b/i', $body, basename($path) . ' contains provider identity/contact');
        }
    }

    #[Test]
    public function the_request_sends_the_approved_prompt_plus_the_response_contract_and_a_strict_route_key_enum(): void
    {
        $this->ask(AiAssistantConfig::TYPE_WLP_SUPPORT, 'Where can I find training materials?', $this->envelope('Answer', 'resources'), $captured);

        [$model, $instructions, $messages, $tools, $options] = $captured;

        $this->assertStringContainsString('FCH WLP SUPPORT ASSISTANT - PRODUCTION SYSTEM PROMPT v1.3.2', $instructions);
        $this->assertStringContainsString('RESPONSE FORMAT', $instructions);
        $this->assertStringContainsString('Resources -> resources', $instructions);

        $enum = $options['response_format']['schema']['properties']['navigation']['properties']['route_key']['enum'];
        $this->assertEqualsCanonicalizing([...array_keys(config('assistant.navigation.wlp_support')), null], $enum);
        $this->assertTrue($options['response_format']['schema']['additionalProperties'] === false);

        // Nothing about the page the user is on is ever forwarded.
        $this->assertStringNotContainsStringIgnoringCase('current_page', json_encode($captured));
    }

    // ---- 2. WLP navigation acceptance tests --------------------------------

    public static function wlpNavigationCases(): array
    {
        return [
            'training materials' => ['Where can I find training materials?', 'resources', '/resources'],
            'clients' => ['Where can I find my clients?', 'clients', '/clients'],
            'embedded form' => ['Where do I configure the embedded form?', 'embedded_form_configuration', '/embed-form/settings'],
            'funding request' => ['Where do I start a funding request?', 'funding_request', '/intake-review/funding-request'],
            'outgoing email' => ['Where do I configure outgoing email?', 'settings', '/settings'],
        ];
    }

    #[Test]
    #[DataProvider('wlpNavigationCases')]
    public function wlp_take_me_there_resolves_to_the_approved_route(string $question, string $routeKey, string $path): void
    {
        $result = $this->ask(
            AiAssistantConfig::TYPE_WLP_SUPPORT,
            $question,
            $this->envelope("Here is where to go.\n\n**Take Me There**", $routeKey)
        );

        $this->assertCount(1, $result['actions']);
        $this->assertSame($path, $result['actions'][0]['path']);
        $this->assertSame("navigate_{$routeKey}", $result['actions'][0]['id']);
        $this->assertSame(['required' => true, 'intent' => 'portal_navigation', 'route_key' => $routeKey], $result['navigation']);
        $this->assertStringContainsString("[[ACTION:navigate_{$routeKey}]]", $result['assistant_message']->content);
        $this->assertStringNotContainsString('Take Me There', $result['assistant_message']->content);
        $this->assertStringNotContainsString($path, $result['assistant_message']->content, 'raw route must never be shown');
    }

    #[Test]
    public function the_outgoing_email_answer_keeps_its_select_the_tab_instruction_on_the_settings_route(): void
    {
        $result = $this->ask(
            AiAssistantConfig::TYPE_WLP_SUPPORT,
            'Where do I configure outgoing email?',
            $this->envelope("**Take Me There**\nThen select Email Configuration.", 'settings')
        );

        $this->assertSame('/settings', $result['actions'][0]['path']);
        $this->assertStringContainsString('Then select Email Configuration.', $result['assistant_message']->content);
    }

    #[Test]
    public function the_same_line_form_take_me_there_arrow_then_select_is_also_converted(): void
    {
        $result = $this->ask(
            AiAssistantConfig::TYPE_WLP_SUPPORT,
            'Where do I test mail?',
            $this->envelope("Verify it.\n\n**Take Me There** -> Then select Test Mail", 'settings')
        );

        $this->assertSame('/settings', $result['actions'][0]['path']);
        $this->assertStringContainsString("[[ACTION:navigate_settings]]\nThen select Test Mail", $result['assistant_message']->content);
        $this->assertStringNotContainsString('Take Me There', $result['assistant_message']->content);
    }

    #[Test]
    public function every_wlp_and_borrower_route_key_maps_to_exactly_the_approved_path(): void
    {
        $wlp = [
            'leads' => '/leads', 'clients' => '/clients',
            // Approved map says '/pre-aualification' (typo, no such route); the real one is used.
            'pre_qualification' => '/intake-review/pre-qualification',
            'funding_request' => '/intake-review/funding-request', 'funding_application' => '/intake-review/funding-application',
            'commissions' => '/commissions', 'invoices' => '/invoices', 'payments' => '/payments', 'subscriptions' => '/subscriptions',
            'upcoming_billing' => '/billing', 'appointments' => '/appointments', 'task_reminders' => '/reminders',
            'resources' => '/resources', 'employees' => '/employees', 'users' => '/users', 'roles_permissions' => '/roles-permissions',
            'embedded_form_configuration' => '/embed-form/settings', 'embedded_form_loan_applications' => '/embed-form/loan-applications',
            'settings' => '/settings',
        ];
        $borrower = [
            'pre_qualification' => '/pre-qualification', 'funding_request' => '/funding-request', 'my_applications' => '/my-applications',
            'capital_access' => '/capital-access', 'invoices' => '/invoices', 'payments' => '/payments', 'billing_details' => '/billing-details',
            'documents' => '/documents', 'resource_hub' => '/resources', 'appointments' => '/appointment', 'funding' => '/funding',
            'credit_builder_overview' => '/credit-builder/overview', 'credit_builder_guide' => '/credit-builder/guide',
            'credit_builder_credit_accounts' => '/credit-builder/credit-accounts', 'profile' => '/profile',
        ];

        $this->assertSame($wlp, array_map(fn ($e) => $e['path'], config('assistant.navigation.wlp_support')));
        $this->assertSame($borrower, array_map(fn ($e) => $e['path'], config('assistant.navigation.borrower_support')));
    }

    // ---- 3. Never default / never inherit ----------------------------------

    public static function badNavigationCases(): array
    {
        return [
            'unknown key' => [['required' => true, 'intent' => 'portal_navigation', 'route_key' => 'admin_secret']],
            'raw url as key' => [['required' => true, 'intent' => 'portal_navigation', 'route_key' => 'https://evil.test/x']],
            'null key' => [['required' => true, 'intent' => 'portal_navigation', 'route_key' => null]],
            'wrong intent' => [['required' => true, 'intent' => 'something_else', 'route_key' => 'resources']],
            'not required' => [['required' => false, 'intent' => 'portal_navigation', 'route_key' => 'resources']],
            'missing navigation' => [null],
        ];
    }

    #[Test]
    #[DataProvider('badNavigationCases')]
    public function without_an_approved_route_key_there_is_no_button_and_never_a_default_page(?array $navigation): void
    {
        $raw = json_encode(['answer' => "Go here.\n\n**Take Me There**", 'navigation' => $navigation]);

        $result = $this->ask(AiAssistantConfig::TYPE_WLP_SUPPORT, 'Where is x?', $raw);

        $this->assertSame([], $result['actions']);
        $this->assertSame(['required' => false, 'intent' => 'none', 'route_key' => null], $result['navigation']);
        $this->assertStringNotContainsString('[[ACTION', $result['assistant_message']->content);
        $this->assertStringNotContainsString('Take Me There', $result['assistant_message']->content);
    }

    #[Test]
    public function a_route_key_from_the_other_assistants_map_is_rejected(): void
    {
        // 'resources' is a WLP key; the borrower's key is 'resource_hub'.
        $borrower = $this->ask(AiAssistantConfig::TYPE_BORROWER_SUPPORT, 'Where are guides?', $this->envelope("Here.\n\n**Take Me There**", 'resources'));
        $this->assertSame([], $borrower['actions']);
    }

    #[Test]
    public function navigation_set_without_a_cta_line_still_gets_the_action_appended(): void
    {
        $result = $this->ask(AiAssistantConfig::TYPE_WLP_SUPPORT, 'Where are clients?', $this->envelope('Your clients are in the Clients area.', 'clients'));

        $this->assertSame('/clients', $result['actions'][0]['path']);
        $this->assertStringEndsWith('[[ACTION:navigate_clients]]', $result['assistant_message']->content);
    }

    #[Test]
    public function a_placeholder_token_written_by_the_model_can_never_create_an_action(): void
    {
        $result = $this->ask(AiAssistantConfig::TYPE_WLP_SUPPORT, 'Hi', $this->envelope('Try [[ACTION:navigate_settings]] now.'));

        $this->assertSame([], $result['actions']);
        $this->assertStringNotContainsString('[[ACTION', $result['assistant_message']->content);
    }

    #[Test]
    public function the_action_path_comes_from_the_server_map_regardless_of_the_current_page_context(): void
    {
        // Same question, asked "from Settings" and "from Clients": the backend never sees
        // the page, so both must land on the identical approved route.
        $paths = [];
        foreach (['settings', 'clients'] as $ignoredStartingPage) {
            $r = $this->ask(AiAssistantConfig::TYPE_WLP_SUPPORT, 'Where can I find training materials?', $this->envelope("Here.\n\n**Take Me There**", 'resources'));
            $paths[] = $r['actions'][0]['path'];
        }
        $this->assertSame(['/resources', '/resources'], $paths);
    }

    #[Test]
    public function truncated_or_non_json_model_output_becomes_a_safe_error_reply_not_raw_json(): void
    {
        foreach (['{"answer": "cut off', 'plain text, not json', json_encode(['answer' => '   ', 'navigation' => null])] as $bad) {
            $result = $this->ask(AiAssistantConfig::TYPE_WLP_SUPPORT, 'Hi', $bad);
            $this->assertStringContainsString('something went wrong', $result['assistant_message']->content);
            $this->assertSame('invalid_response', $result['escalation']['reason']);
            $this->assertSame([], $result['actions']);
        }
    }

    // ---- 4. Borrower navigation + white-label protection -------------------

    public static function borrowerNavigationCases(): array
    {
        return [
            'training materials' => ['Where can I find training materials?', 'resource_hub', '/resources'],
            'applications' => ['Where can I find my applications?', 'my_applications', '/my-applications'],
            'documents' => ['Where do I find my documents?', 'documents', '/documents'],
            'credit builder overview' => ['Where is the Credit Builder overview?', 'credit_builder_overview', '/credit-builder/overview'],
            'credit builder guide' => ['Where is the Credit Builder step-by-step guide?', 'credit_builder_guide', '/credit-builder/guide'],
            'profile' => ['Where do I find my profile?', 'profile', '/profile'],
        ];
    }

    #[Test]
    #[DataProvider('borrowerNavigationCases')]
    public function borrower_take_me_there_resolves_to_the_approved_route(string $question, string $routeKey, string $path): void
    {
        $result = $this->ask(AiAssistantConfig::TYPE_BORROWER_SUPPORT, $question, $this->envelope("You can find it here.\n\n**Take Me There**", $routeKey));

        $this->assertSame($path, $result['actions'][0]['path']);
        $this->assertSame(['required' => true, 'intent' => 'portal_navigation', 'route_key' => $routeKey], $result['navigation']);
    }

    #[Test]
    public function the_borrower_request_carries_v1_5_and_a_trusted_context_that_never_names_the_provider(): void
    {
        $this->ask(AiAssistantConfig::TYPE_BORROWER_SUPPORT, 'Hi', $this->envelope('Hello'), $captured);
        $instructions = $captured[1];

        $this->assertStringContainsString('BORROWER SUPPORT ASSISTANT — INSTRUCTIONS v1.5', $instructions);
        $this->assertStringContainsString('Resource Hub -> resource_hub', $instructions);

        // The tenant here IS "Four Corner Funding" with an fourcornerfunding.com email:
        // both must be withheld from the trusted context rather than handed to the model.
        $context = substr($instructions, strrpos($instructions, 'TRUSTED TENANT CONTEXT'));
        $this->assertStringContainsString('provider_display_name: your provider', $context);
        $this->assertStringNotContainsString('fourcornerfunding', strtolower($context));
        $this->assertStringContainsString('(none supplied', $context);
    }

    public static function providerLeaks(): array
    {
        return [
            'craig email' => ['Please email Craig Rice at craig.rice@fourcornerfunding.com.'],
            'support mailbox' => ['Contact support@fourcornerfunding.com for help.'],
            'company name' => ['This is run by Four Corner Holdings.'],
            'initialism' => ['That is handled by FCH staff.'],
            'channel' => ['Watch https://www.youtube.com/@fourcornerfundinghub'],
            'sharepoint' => ['Download it at https://x-my.sharepoint.com/:b:/g/abc'],
        ];
    }

    #[Test]
    #[DataProvider('providerLeaks')]
    public function a_borrower_reply_that_exposes_the_provider_is_replaced_by_the_neutral_handoff(string $leaky): void
    {
        $result = $this->ask(
            AiAssistantConfig::TYPE_BORROWER_SUPPORT,
            'I need someone to help me with my account. Who should I email?',
            $this->envelope($leaky . "\n\n**Take Me There**", 'resource_hub')
        );

        $content = $result['assistant_message']->content;
        $this->assertSame(
            'This requires review by your support team. Please use the contact or support method provided in your portal or by the company you are working with.',
            $content
        );
        $this->assertSame([], $result['actions']);
        $this->assertNull($result['escalation']['route'], 'borrower escalation must not carry a provider mailto');
        $this->assertTrue($result['escalation']['required']);
        $this->assertTrue($result['policy']['restricted_content_blocked']);
        $this->assertDoesNotMatchRegularExpression('/craig|four\s*corner|fch/i', json_encode($result['escalation']) . $content);
    }

    #[Test]
    public function borrower_escalation_and_error_paths_never_expose_a_provider_route(): void
    {
        $neutral = $this->ask(
            AiAssistantConfig::TYPE_BORROWER_SUPPORT,
            'Who do I contact?',
            $this->envelope('This requires review by your support team. Please use the contact or support method provided in your portal.')
        );
        $this->assertTrue($neutral['escalation']['required']);
        $this->assertNull($neutral['escalation']['route']);

        // upstream failure
        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('defaultModel')->andReturn('gpt-test');
            $mock->shouldReceive('defaultReasoningEffort')->andReturn('low');
            $mock->shouldReceive('runChat')->once()->andReturn(['ok' => false, 'error' => 'boom']);
        });
        $service = app(SupportAssistantService::class);
        $conversation = $service->startNewConversation($this->borrowerUser, AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $failed = $service->sendMessage($conversation, 'x', $this->borrowerUser);
        $this->assertNull($failed['escalation']['route']);
    }

    #[Test]
    public function the_wlp_escalation_keeps_its_fch_route_because_a_wlp_is_an_fch_partner(): void
    {
        $result = $this->ask(
            AiAssistantConfig::TYPE_WLP_SUPPORT,
            'What lender?',
            $this->envelope('This requires review by the Four Corner Funding team. Please email support@fourcornerfunding.com.')
        );
        $this->assertSame('mailto:support@fourcornerfunding.com', $result['escalation']['route']);
        $this->assertStringContainsString('fourcornerfunding.com', $result['assistant_message']->content);
    }

    #[Test]
    public function file_search_citations_that_would_expose_the_provider_are_dropped_for_borrowers_only(): void
    {
        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('defaultModel')->andReturn('gpt-test');
            $mock->shouldReceive('defaultReasoningEffort')->andReturn('low');
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true, 'text' => $this->envelope('Safe answer.'), 'model' => 'gpt-test', 'usage' => null, 'request_id' => 'r',
                'sources' => [['title' => 'FCH Internal Contacts.pdf'], ['title' => 'Getting Started.md']],
            ]);
        });
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $config->vector_store_id = 'vs_x';
        $config->save();

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->borrowerUser, AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $result = $service->sendMessage($conversation, 'Hi', $this->borrowerUser);

        $this->assertSame([['title' => 'Getting Started.md']], $result['sources']);
    }

    // ---- 5. Prompt versioning: swap, audit, rollback -----------------------

    #[Test]
    public function every_reply_is_stamped_with_the_exact_prompt_version_and_hash_that_produced_it(): void
    {
        $result = $this->ask(AiAssistantConfig::TYPE_WLP_SUPPORT, 'Hi', $this->envelope('Hello', 'clients'));
        $msg = $result['assistant_message']->fresh();

        $active = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->activePrompt();
        $this->assertSame('1.3.2', $msg->prompt_version);
        $this->assertSame($active['sha256'], $msg->prompt_sha256);
        $this->assertSame('file', $msg->prompt_source);
        $this->assertSame('clients', $msg->navigation['route_key']);
    }

    #[Test]
    public function a_new_prompt_version_takes_effect_on_the_next_message_and_can_be_rolled_back_with_no_code_change(): void
    {
        $prompts = app(AssistantPromptService::class);
        $type = AiAssistantConfig::TYPE_WLP_SUPPORT;
        $body = "WLP PROMPT v1.3.3 — approved wording change.\n" . str_repeat('Approved behaviour line. ', 20);

        $v133 = $prompts->create($type, '1.3.3', $body, 'wording tweak');
        $this->assertFalse($v133->is_active, 'creating a version must not release it');

        $before = $this->ask($type, 'Hi', $this->envelope('a'), $c1);
        $this->assertStringContainsString('PRODUCTION SYSTEM PROMPT v1.3.2', $c1[1]);
        $this->assertSame('1.3.2', $before['assistant_message']->prompt_version);

        $prompts->activate($v133);
        $after = $this->ask($type, 'Hi', $this->envelope('b'), $c2);
        $this->assertStringContainsString('WLP PROMPT v1.3.3', $c2[1]);
        $this->assertStringNotContainsString('PRODUCTION SYSTEM PROMPT v1.3.2', $c2[1]);
        $this->assertSame('1.3.3', $after['assistant_message']->prompt_version);
        $this->assertSame('database', $after['assistant_message']->prompt_source);
        $this->assertSame(hash('sha256', $v133->content), $after['assistant_message']->prompt_sha256);

        // rollback = back to the code-shipped approved prompt
        $prompts->revertToBundled($type);
        $rolled = $this->ask($type, 'Hi', $this->envelope('c'), $c3);
        $this->assertStringContainsString('PRODUCTION SYSTEM PROMPT v1.3.2', $c3[1]);
        $this->assertSame('file', $rolled['assistant_message']->prompt_source);
    }

    #[Test]
    public function activating_an_older_database_version_is_a_rollback_and_only_one_is_ever_active(): void
    {
        $prompts = app(AssistantPromptService::class);
        $type = AiAssistantConfig::TYPE_BORROWER_SUPPORT;
        $a = $prompts->create($type, '1.5', 'BORROWER v1.5 ' . str_repeat('x ', 200));
        $b = $prompts->create($type, '1.6', 'BORROWER v1.6 ' . str_repeat('y ', 200));

        $prompts->activate($a);
        $prompts->activate($b);
        $this->assertSame(1, AiAssistantPromptVersion::where('assistant_type', $type)->where('is_active', true)->count());
        $this->assertSame('1.6', AiAssistantConfig::find($type)->activePrompt()['version']);

        $prompts->activate($a); // rollback
        $this->assertSame('1.5', AiAssistantConfig::find($type)->activePrompt()['version']);
        $this->assertSame(1, AiAssistantPromptVersion::where('assistant_type', $type)->where('is_active', true)->count());
    }

    #[Test]
    public function versions_are_immutable_and_invalid_input_is_refused(): void
    {
        $prompts = app(AssistantPromptService::class);
        $type = AiAssistantConfig::TYPE_WLP_SUPPORT;
        $prompts->create($type, '2.0', str_repeat('z', 300));

        foreach ([
            fn () => $prompts->create($type, '2.0', str_repeat('q', 300)),      // duplicate version
            fn () => $prompts->create($type, '2.1', 'too short'),               // not a real prompt
            fn () => $prompts->create($type, 'bad version!', str_repeat('q', 300)),
            fn () => $prompts->create('rogue_type', '1.0', str_repeat('q', 300)),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
