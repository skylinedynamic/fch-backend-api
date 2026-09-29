<?php

namespace Tests\Integration\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\ChatMessage;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Assistant\SupportAssistantService;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SupportAssistantService end-to-end against a real database, with only the
 * OpenAI HTTP boundary mocked (OpenAiResponsesService::runChat) — matching
 * this suite's Integration-tier convention of mocking just the outside
 * world. Covers the behavior verified manually during development: the
 * not-ready gate, the bold "**Take Me There**" marker -> action mapping, the
 * server-owned action allowlist, sensitive-input redaction, escalation-
 * phrase detection, and the borrower assistant's white-label backstop.
 */
class SupportAssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private User $partnerUser;
    private Borrower $borrower;
    private User $borrowerUser;

    /** Real, on-disk prompt file contents as found before this test touched them. */
    private array $originalSystemPrompts = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (AiAssistantConfig::TYPES as $type) {
            $path = (new AiAssistantConfig($type))->systemPromptPath();
            $this->originalSystemPrompts[$type] = file_exists($path) ? file_get_contents($path) : '';
        }

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Johnathan',
            'last_name' => 'Smithfield',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);

        $this->partnerUser = $this->makeUser(User::PARTNER, ['partner_id' => $this->partner->id]);
        $this->borrowerUser = $this->makeUser(User::BORROWER, [
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
        ]);
    }

    private function makeUser(string $userType, array $attributes = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return User::findOrFail($id);
    }

    /**
     * The system prompt lives in a real file, not a DB column — write it
     * directly for tests that need isReady() to be true. tearDown() restores
     * whatever was actually on disk before this test ran (never a hardcoded
     * blank), so this never clobbers real content someone is testing with
     * outside the suite.
     */
    private function writeSystemPrompt(string $assistantType, string $content): void
    {
        file_put_contents((new AiAssistantConfig($assistantType))->systemPromptPath(), $content);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSystemPrompts as $type => $content) {
            $this->writeSystemPrompt($type, $content);
        }

        parent::tearDown();
    }

    private function enabledConfig(string $type, string $systemPrompt = 'You are a test assistant.'): AiAssistantConfig
    {
        $this->writeSystemPrompt($type, $systemPrompt);

        $config = AiAssistantConfig::find($type);
        $config->model = 'gpt-test';
        $config->vector_store_id = 'vs_test_123';
        $config->is_enabled = true;
        $config->save();

        return $config;
    }

    #[Test]
    public function an_unconfigured_assistant_never_calls_openai_and_flags_escalation(): void
    {
        // No AiAssistantConfig row at all for this type.
        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldNotReceive('runChat');
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'Hello?', $this->partnerUser);

        $this->assertStringContainsString('not available yet', $result['assistant_message']->content);
        $this->assertTrue($result['escalation']['required']);
        $this->assertSame('assistant_not_configured', $result['escalation']['reason']);
        $this->assertFalse($result['policy']['live_data_used']);
    }

    #[Test]
    public function a_disabled_config_also_short_circuits_without_calling_openai(): void
    {
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT);
        $config->vector_store_id = 'vs_test_123';
        $config->is_enabled = false;
        $config->save();

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldNotReceive('runChat');
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'Hello?', $this->partnerUser);

        $this->assertTrue($result['escalation']['required']);
    }

    #[Test]
    public function the_bold_take_me_there_marker_becomes_the_server_owned_action_and_is_removed_from_the_text(): void
    {
        $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true,
                'text' => "Go to Settings.\n\n**Take Me There**\n\nThen select **Email Configuration**.",
                'sources' => [],
                'model' => 'gpt-test',
                'usage' => null,
                'request_id' => 'req_test',
            ]);
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'How do I set up email?', $this->partnerUser);

        $this->assertStringNotContainsString('Take Me There', $result['assistant_message']->content);
        $this->assertStringContainsString('[[ACTION:settings_home]]', $result['assistant_message']->content);
        $this->assertCount(1, $result['actions']);
        $this->assertSame([
            'type' => 'navigate',
            'id' => 'settings_home',
            'label' => 'Take Me There',
            'path' => '/admin/settings',
        ], $result['actions'][0]);
    }

    #[Test]
    public function a_reply_with_no_marker_produces_no_action(): void
    {
        $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true,
                'text' => 'Just a plain answer with no CTA.',
                'sources' => [],
                'model' => 'gpt-test',
                'usage' => null,
                'request_id' => 'req_test',
            ]);
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'Just a question', $this->partnerUser);

        $this->assertSame([], $result['actions']);
    }

    #[Test]
    public function an_ssn_like_pattern_in_the_users_message_is_redacted_before_it_is_stored(): void
    {
        $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true,
                'text' => 'Please do not share that.',
                'sources' => [],
                'model' => 'gpt-test',
                'usage' => null,
                'request_id' => 'req_test',
            ]);
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'my ssn is 123-45-6789, please help', $this->partnerUser);

        $this->assertSame('my ssn is [redacted-ssn], please help', $result['user_message']->content);
        $this->assertTrue($result['policy']['sensitive_input_detected']);
    }

    #[Test]
    public function ordinary_input_is_not_flagged_as_sensitive(): void
    {
        $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true,
                'text' => 'Sure, here is how.',
                'sources' => [],
                'model' => 'gpt-test',
                'usage' => null,
                'request_id' => 'req_test',
            ]);
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'How do I add a new client?', $this->partnerUser);

        $this->assertFalse($result['policy']['sensitive_input_detected']);
        $this->assertSame('How do I add a new client?', $result['user_message']->content);
    }

    #[Test]
    public function the_escalation_phrase_in_a_reply_sets_escalation_required(): void
    {
        $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true,
                'text' => 'This requires review by the Four Corner Funding team. Please email support@fourcornerfunding.com.',
                'sources' => [],
                'model' => 'gpt-test',
                'usage' => null,
                'request_id' => 'req_test',
            ]);
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'What lender did you use?', $this->partnerUser);

        $this->assertTrue($result['escalation']['required']);
        $this->assertSame('escalation_language_in_reply', $result['escalation']['reason']);
    }

    #[Test]
    public function the_borrower_assistant_redacts_a_white_label_boundary_leak_as_a_backstop(): void
    {
        $this->enabledConfig(AiAssistantConfig::TYPE_BORROWER_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true,
                // Simulates a prompt failure: the model should never say this to a borrower.
                'text' => 'This platform is powered by Four Corner Funding (FCH).',
                'sources' => [],
                'model' => 'gpt-test',
                'usage' => null,
                'request_id' => 'req_test',
            ]);
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->borrowerUser, AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $result = $service->sendMessage($conversation, 'Who runs this platform?', $this->borrowerUser);

        $this->assertStringNotContainsString('Four Corner Funding', $result['assistant_message']->content);
        $this->assertStringNotContainsString('FCH', $result['assistant_message']->content);
        $this->assertTrue($result['policy']['restricted_content_blocked']);
    }

    #[Test]
    public function the_wlp_assistant_does_not_apply_the_white_label_backstop(): void
    {
        $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true,
                'text' => 'Four Corner Funding requires this for all WLPs.',
                'sources' => [],
                'model' => 'gpt-test',
                'usage' => null,
                'request_id' => 'req_test',
            ]);
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'Whose policy is this?', $this->partnerUser);

        $this->assertStringContainsString('Four Corner Funding', $result['assistant_message']->content);
        $this->assertFalse($result['policy']['restricted_content_blocked']);
    }

    #[Test]
    public function each_user_gets_their_own_conversation_and_cannot_resolve_someone_elses(): void
    {
        $service = app(SupportAssistantService::class);

        $mine = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $theirs = $service->startOrGetConversation($this->borrowerUser, AiAssistantConfig::TYPE_BORROWER_SUPPORT);

        $this->assertNotSame($mine->id, $theirs->id);

        $this->assertNull(
            $service->findOwnConversationByUuid($theirs->uuid, $this->partnerUser, AiAssistantConfig::TYPE_BORROWER_SUPPORT),
            'a user must never resolve a conversation uuid that belongs to someone else'
        );
        $this->assertNotNull(
            $service->findOwnConversationByUuid($theirs->uuid, $this->borrowerUser, AiAssistantConfig::TYPE_BORROWER_SUPPORT)
        );
    }

    #[Test]
    public function repeated_sends_reuse_the_same_open_conversation(): void
    {
        $service = app(SupportAssistantService::class);

        $first = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $second = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->assertSame($first->id, $second->id);
    }

    #[Test]
    public function start_new_conversation_closes_the_old_open_one_instead_of_leaving_two_open(): void
    {
        $service = app(SupportAssistantService::class);

        $original = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $fresh = $service->startNewConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->assertNotSame($original->id, $fresh->id);
        $this->assertSame('closed', $original->fresh()->status);
        $this->assertSame('open', $fresh->status);

        // startOrGetConversation must now resume the new one, not resurrect the closed one.
        $resumed = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $this->assertSame($fresh->id, $resumed->id);
    }

    #[Test]
    public function every_message_gets_a_uuid_never_relying_on_the_internal_integer_id(): void
    {
        $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('runChat')->once()->andReturn([
                'ok' => true,
                'text' => 'Answer.',
                'sources' => [],
                'model' => 'gpt-test',
                'usage' => null,
                'request_id' => 'req_test',
            ]);
        });

        $service = app(SupportAssistantService::class);
        $conversation = $service->startOrGetConversation($this->partnerUser, AiAssistantConfig::TYPE_WLP_SUPPORT);
        $result = $service->sendMessage($conversation, 'Hi', $this->partnerUser);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $conversation->uuid
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result['assistant_message']->uuid
        );
    }

    #[Test]
    public function derive_widget_intro_returns_the_live_generated_subtitle_and_suggestions(): void
    {
        $config = $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) use ($config) {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (...$args) => str_contains(json_encode($args[2]), $config->systemPrompt()))
                ->andReturn([
                    'ok' => true,
                    'data' => [
                        'welcome_subtitle' => 'Ask a question about testing.',
                        'quick_suggestions' => ['How do I run a test?', 'Where are the docs?'],
                    ],
                ]);
        });

        $service = app(SupportAssistantService::class);
        $intro = $service->deriveWidgetIntro($config);

        $this->assertSame('Ask a question about testing.', $intro['welcome_subtitle']);
        $this->assertSame(['How do I run a test?', 'Where are the docs?'], $intro['quick_suggestions']);
    }

    #[Test]
    public function derive_widget_intro_caches_by_prompt_content_and_never_calls_openai_twice_for_the_same_prompt(): void
    {
        $config = $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn([
                'ok' => true,
                'data' => ['welcome_subtitle' => 'Cached line.', 'quick_suggestions' => ['Cached question?']],
            ]);
        });

        $service = app(SupportAssistantService::class);
        $first = $service->deriveWidgetIntro($config);
        $second = $service->deriveWidgetIntro($config);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function derive_widget_intro_regenerates_once_the_prompt_text_actually_changes(): void
    {
        $config = $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('run')->twice()->andReturn(
                ['ok' => true, 'data' => ['welcome_subtitle' => 'First line.', 'quick_suggestions' => ['First question?']]],
                ['ok' => true, 'data' => ['welcome_subtitle' => 'Second line.', 'quick_suggestions' => ['Second question?']]],
            );
        });

        $service = app(SupportAssistantService::class);
        $before = $service->deriveWidgetIntro($config);

        $this->writeSystemPrompt(AiAssistantConfig::TYPE_WLP_SUPPORT, 'A completely different prompt now.');
        $after = $service->deriveWidgetIntro($config);

        $this->assertSame('First line.', $before['welcome_subtitle']);
        $this->assertSame('Second line.', $after['welcome_subtitle']);
    }

    #[Test]
    public function derive_widget_intro_falls_back_to_a_generic_default_without_caching_a_model_failure(): void
    {
        $config = $this->enabledConfig(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('run')->twice()->andReturn(
                ['ok' => false, 'error' => 'upstream error'],
                ['ok' => true, 'data' => ['welcome_subtitle' => 'Recovered line.', 'quick_suggestions' => ['Recovered question?']]],
            );
        });

        $service = app(SupportAssistantService::class);
        $failed = $service->deriveWidgetIntro($config);
        $this->assertSame(['How can you help me?'], $failed['quick_suggestions']);

        // The failure must not have been cached — the very next call retries OpenAI.
        $retried = $service->deriveWidgetIntro($config);
        $this->assertSame('Recovered line.', $retried['welcome_subtitle']);
    }

    #[Test]
    public function derive_widget_intro_never_calls_openai_for_a_blank_prompt(): void
    {
        // Force blank explicitly — don't rely on the real prompt file happening
        // to be empty, since it may hold real content someone is testing with.
        $this->writeSystemPrompt(AiAssistantConfig::TYPE_WLP_SUPPORT, '');
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT);

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldNotReceive('run');
        });

        $service = app(SupportAssistantService::class);
        $intro = $service->deriveWidgetIntro($config);

        $this->assertSame(['How can you help me?'], $intro['quick_suggestions']);
    }
}
