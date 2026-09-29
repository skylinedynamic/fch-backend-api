<?php

namespace Tests\Feature\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\ChatConversation;
use App\Models\Assistant\ChatMessage;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HTTP-level checks for both assistant route stacks: the not-ready gate end
 * to end (no OpenAI call needed — the fast path is entered before the
 * OpenAiResponsesService is ever touched), and that a caller can never read,
 * or list into, another user's session via the sessions/history endpoints.
 */
class AssistantAccessTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private User $partnerUser;
    private User $otherPartnerUser;
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

        $otherPartner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Another Partner Co',
            'full_name' => 'Another Partner Co',
            'email' => 'other-' . uniqid() . '@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Johnathan',
            'last_name' => 'Smithfield',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);

        $this->partnerUser = $this->makeUser(User::PARTNER, ['partner_id' => $this->partner->id]);
        $this->otherPartnerUser = $this->makeUser(User::PARTNER, ['partner_id' => $otherPartner->id]);
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

    #[Test]
    public function the_wlp_assistant_answers_with_a_safe_placeholder_when_unconfigured(): void
    {
        // Force disabled explicitly — don't rely on config/assistant.php's
        // own default, which a deploy may have flipped to true.
        config(['assistant.wlp_support.is_enabled' => false]);
        Passport::actingAs($this->partnerUser, [], 'api');

        $response = $this->postJson('/api/v1/common/assistant/wlp/messages', [
            'message' => 'Can you help me?',
        ])->assertOk();

        $response->assertJsonPath('data.escalation.required', true);
        $this->assertStringContainsString('not available yet', $response->json('data.answer'));
        $this->assertNotEmpty($response->json('data.session_id'));
        $this->assertNotEmpty($response->json('data.message_id'));
    }

    #[Test]
    public function the_borrower_assistant_answers_with_a_safe_placeholder_when_unconfigured(): void
    {
        // Force disabled explicitly — don't rely on config/assistant.php's
        // own default, which a deploy may have flipped to true.
        config(['assistant.borrower_support.is_enabled' => false]);
        Passport::actingAs($this->borrowerUser, [], 'api');

        $this->postJson('/api/v1/borrower/assistant/messages', [
            'message' => 'Can you help me?',
        ])->assertOk()->assertJsonPath('data.escalation.required', true);
    }

    #[Test]
    public function the_reply_timestamp_is_formatted_in_the_callers_own_timezone(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        // toTimezone() (app/Helpers/helpers.php) reads this header — the same
        // one apiClient attaches on every request in both frontends — so the
        // formatted string must reflect it, not the server's own timezone.
        $response = $this->withHeaders(['X-Timezone' => 'America/New_York'])
            ->postJson('/api/v1/common/assistant/wlp/messages', [
                'message' => 'What time is it?',
            ])->assertOk();

        $createdAt = $response->json('data.created_at');
        $this->assertNotEmpty($createdAt);

        $expected = \App\Models\Assistant\ChatMessage::latest('id')->first()
            ->created_at->setTimezone('America/New_York')->format('M j, Y g:i A');
        $this->assertSame($expected, $createdAt);
    }

    #[Test]
    public function a_message_over_4000_characters_is_rejected(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->postJson('/api/v1/common/assistant/wlp/messages', [
            'message' => str_repeat('a', 4001),
        ])->assertStatus(422);
    }

    #[Test]
    public function a_user_cannot_read_another_users_session_history(): void
    {
        $conversation = ChatConversation::create([
            'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
            'partner_id' => $this->partner->id,
            'causer_type' => User::class,
            'causer_id' => $this->partnerUser->id,
            'status' => 'open',
        ]);
        $conversation->messages()->create([
            'role' => ChatMessage::ROLE_USER,
            'content' => 'A private question only I should be able to read',
        ]);

        Passport::actingAs($this->otherPartnerUser, [], 'api');
        $this->getJson("/api/v1/common/assistant/wlp/conversations/{$conversation->uuid}/messages")
            ->assertStatus(404);

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->getJson("/api/v1/common/assistant/wlp/conversations/{$conversation->uuid}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function posting_to_sessions_closes_the_current_open_conversation_and_starts_a_fresh_one(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $first = $this->postJson('/api/v1/common/assistant/wlp/messages', ['message' => 'first thread'])
            ->assertOk()->json('data.session_id');

        $response = $this->postJson('/api/v1/common/assistant/wlp/sessions')->assertOk();
        $newSessionId = $response->json('data.session_id');

        $this->assertNotSame($first, $newSessionId, 'starting a new conversation must not reuse the existing open one');

        $original = ChatConversation::where('uuid', $first)->first();
        $this->assertSame('closed', $original->status, 'the prior open conversation must be closed, not left open alongside the new one');

        // The next ordinary message now lands in the new session, not the old one.
        $second = $this->postJson('/api/v1/common/assistant/wlp/messages', ['message' => 'second thread'])
            ->assertOk()->json('data.session_id');
        $this->assertSame($newSessionId, $second);
    }

    #[Test]
    public function the_sessions_list_returns_only_the_callers_own_sessions(): void
    {
        ChatConversation::create([
            'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
            'partner_id' => $this->partner->id,
            'causer_type' => User::class,
            'causer_id' => $this->partnerUser->id,
            'status' => 'open',
        ]);
        ChatConversation::create([
            'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
            'partner_id' => $this->otherPartnerUser->partner_id,
            'causer_type' => User::class,
            'causer_id' => $this->otherPartnerUser->id,
            'status' => 'open',
        ]);

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->getJson('/api/v1/common/assistant/wlp/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function deleting_sessions_only_removes_the_callers_own(): void
    {
        $mine = ChatConversation::create([
            'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
            'partner_id' => $this->partner->id,
            'causer_type' => User::class,
            'causer_id' => $this->partnerUser->id,
            'status' => 'open',
        ]);
        $theirs = ChatConversation::create([
            'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
            'partner_id' => $this->otherPartnerUser->partner_id,
            'causer_type' => User::class,
            'causer_id' => $this->otherPartnerUser->id,
            'status' => 'open',
        ]);

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->deleteJson('/api/v1/common/assistant/wlp/sessions')->assertOk();

        $this->assertSoftDeleted('chat_conversations', ['id' => $mine->id]);
        $this->assertDatabaseHas('chat_conversations', ['id' => $theirs->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_borrower_cannot_reach_the_wlp_assistant_route(): void
    {
        Passport::actingAs($this->borrowerUser, [], 'api');

        // The borrower guard on this route group rejects a borrower-typed
        // user outright — the tenancy boundary the OpenAPI reference
        // controller enforces via an explicit role check is enforced here by
        // routing/middleware instead.
        $this->postJson('/api/v1/common/assistant/wlp/messages', [
            'message' => 'Can a borrower reach this?',
        ])->assertStatus(403);
    }

    #[Test]
    public function the_wlp_config_endpoint_reports_not_ready_with_no_config_row_and_never_calls_openai(): void
    {
        // Force disabled explicitly — don't rely on config/assistant.php's
        // own default, which a deploy may have flipped to true.
        config(['assistant.wlp_support.is_enabled' => false]);
        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldNotReceive('run');
        });

        Passport::actingAs($this->partnerUser, [], 'api');

        $this->getJson('/api/v1/common/assistant/wlp/config')
            ->assertOk()
            ->assertJsonPath('data.is_ready', false)
            ->assertJsonPath('data.quick_suggestions', []);
    }

    #[Test]
    public function the_wlp_config_endpoint_returns_live_derived_copy_sourced_from_the_saved_prompt_and_is_borrower_blocked(): void
    {
        $this->writeSystemPrompt(AiAssistantConfig::TYPE_WLP_SUPPORT, 'You help WLPs with settings and training.');
        config(['assistant.wlp_support.model' => 'gpt-test', 'assistant.wlp_support.is_enabled' => true]);
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT);
        $config->vector_store_id = 'vs_test_123';
        $config->save();

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn([
                'ok' => true,
                'data' => [
                    'welcome_subtitle' => 'Ask a question about settings or training.',
                    'quick_suggestions' => ['How do I configure email?', 'Where is training?'],
                ],
            ]);
        });

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->getJson('/api/v1/common/assistant/wlp/config')
            ->assertOk()
            ->assertJsonPath('data.is_ready', true)
            ->assertJsonPath('data.welcome_subtitle', 'Ask a question about settings or training.')
            ->assertJsonPath('data.quick_suggestions', ['How do I configure email?', 'Where is training?']);

        Passport::actingAs($this->borrowerUser, [], 'api');
        $this->getJson('/api/v1/common/assistant/wlp/config')->assertStatus(403);
    }

    #[Test]
    public function the_borrower_config_endpoint_returns_live_derived_copy_and_no_system_prompt_field(): void
    {
        $this->writeSystemPrompt(AiAssistantConfig::TYPE_BORROWER_SUPPORT, 'You help borrowers with their application.');
        config(['assistant.borrower_support.model' => 'gpt-test', 'assistant.borrower_support.is_enabled' => true]);
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $config->vector_store_id = 'vs_test_456';
        $config->save();

        $this->mock(OpenAiResponsesService::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn([
                'ok' => true,
                'data' => [
                    'welcome_subtitle' => 'Ask a question about your application.',
                    'quick_suggestions' => ['How do I check my status?'],
                ],
            ]);
        });

        Passport::actingAs($this->borrowerUser, [], 'api');
        $response = $this->getJson('/api/v1/borrower/assistant/config')
            ->assertOk()
            ->assertJsonPath('data.is_ready', true)
            ->assertJsonPath('data.welcome_subtitle', 'Ask a question about your application.')
            ->assertJsonPath('data.quick_suggestions', ['How do I check my status?']);

        $this->assertArrayNotHasKey('system_prompt', $response->json('data'));
    }
}
