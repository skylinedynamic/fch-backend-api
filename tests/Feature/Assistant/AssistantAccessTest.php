<?php

namespace Tests\Feature\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\ChatConversation;
use App\Models\Assistant\ChatMessage;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HTTP-level checks for both assistant route stacks: the not-ready gate end
 * to end (no OpenAI call needed — the fast path is entered before the
 * OpenAiAgentsService is ever touched), and that a caller can never read,
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

    protected function setUp(): void
    {
        parent::setUp();

        // No test here may reach OpenAI: any path that would call the agent
        // fails the test instead.
        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldNotReceive('runAgent');
            $mock->shouldNotReceive('retrieveAgent');
        });

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);

        $otherPartner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
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

    #[Test]
    public function the_wlp_assistant_answers_with_a_safe_placeholder_when_unconfigured(): void
    {
        // No agent id has been saved in Settings.
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
        // No agent id has been saved in Settings.
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
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->getJson('/api/v1/common/assistant/wlp/config')
            ->assertOk()
            ->assertJsonPath('data.is_ready', false)
            ->assertJsonPath('data.quick_suggestions', []);
    }

    #[Test]
    public function the_wlp_config_endpoint_reports_ready_and_is_borrower_blocked(): void
    {
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['agent_id' => 'agent_wlp_test']);
        $this->mockAgentAndIntro('Partner instructions.', 'Get help with partner workflows.', ['How do I add a client?']);

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->getJson('/api/v1/common/assistant/wlp/config?intro=1')
            ->assertOk()
            ->assertJsonPath('data.is_ready', true)
            ->assertJsonPath('data.welcome_subtitle', 'Get help with partner workflows.')
            ->assertJsonPath('data.quick_suggestions', ['How do I add a client?']);

        Passport::actingAs($this->borrowerUser, [], 'api');
        $this->getJson('/api/v1/common/assistant/wlp/config')->assertStatus(403);
    }

    #[Test]
    public function the_borrower_config_endpoint_never_exposes_the_agent_or_its_instructions(): void
    {
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_BORROWER_SUPPORT, ['agent_id' => 'agent_borrower_test']);
        $this->mockAgentAndIntro('SECRET borrower instructions.', 'Get help with your application.', ['How long must I be in business?']);

        Passport::actingAs($this->borrowerUser, [], 'api');
        $response = $this->getJson('/api/v1/borrower/assistant/config?intro=1')
            ->assertOk()
            ->assertJsonPath('data.is_ready', true)
            ->assertJsonPath('data.welcome_subtitle', 'Get help with your application.')
            ->assertJsonPath('data.quick_suggestions', ['How long must I be in business?']);

        $this->assertEqualsCanonicalizing(
            ['is_ready', 'welcome_subtitle', 'quick_suggestions', 'navigation'],
            array_keys($response->json('data'))
        );
        // The approved Take Me There destinations: in-app paths only.
        $this->assertSame('/documents', $response->json('data.navigation.documents'));
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('agent_borrower_test', $response->getContent());
    }

    #[Test]
    public function the_page_load_check_says_whether_to_show_the_chat_without_ever_calling_openai(): void
    {
        // setUp()'s mock fails the test if OpenAI is touched.
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_BORROWER_SUPPORT, ['agent_id' => 'agent_borrower_test']);
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['agent_id' => 'agent_wlp_test', 'enabled' => false]);

        Passport::actingAs($this->borrowerUser, [], 'api');
        $this->getJson('/api/v1/borrower/assistant/config')
            ->assertOk()
            ->assertJsonPath('data.is_ready', true)
            ->assertJsonPath('data.quick_suggestions', []);

        // Disabled by the host: the partner widget is told not to show.
        Passport::actingAs($this->partnerUser, [], 'api');
        $this->getJson('/api/v1/common/assistant/wlp/config')
            ->assertOk()
            ->assertJsonPath('data.is_ready', false);
    }

    #[Test]
    public function the_partner_chat_is_for_white_label_partners_only_never_the_host_or_certified_partners(): void
    {
        // setUp()'s mock fails the test if OpenAI is touched.
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['agent_id' => 'agent_wlp_test']);

        $host = Partner::create([
            'is_host' => true, 'status' => Partner::STATUS_ACTIVE, 'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host', 'full_name' => 'Host', 'email' => 'host-' . uniqid() . '@example.test',
        ]);
        $certified = Partner::create([
            'is_host' => false, 'status' => Partner::STATUS_ACTIVE, 'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Certified', 'full_name' => 'Certified', 'email' => 'cert-' . uniqid() . '@example.test',
        ]);

        $excluded = [
            'host partner user' => $this->makeUser(User::PARTNER, ['partner_id' => $host->id]),
            'host admin user' => $this->makeUser(User::ADMIN, ['partner_id' => $host->id]),
            'certified partner user' => $this->makeUser(User::PARTNER, ['partner_id' => $certified->id]),
        ];

        foreach ($excluded as $who => $user) {
            Passport::actingAs($user, [], 'api');

            // The widget is told not to show...
            $this->getJson('/api/v1/common/assistant/wlp/config?intro=1')
                ->assertOk()
                ->assertJsonPath('data.is_ready', false);
            // ...and the chat itself refuses them even if called directly.
            $this->postJson('/api/v1/common/assistant/wlp/messages', ['message' => 'Hello'])->assertStatus(403);
            $this->getJson('/api/v1/common/assistant/wlp/sessions')->assertStatus(403);
            $this->postJson('/api/v1/common/assistant/wlp/sessions')->assertStatus(403);
        }

        // A user with no partner is refused everything, the widget config too
        // (EnsureUserHasPartner, findings O6).
        Passport::actingAs($this->makeUser(User::ADMIN), [], 'api');
        $this->getJson('/api/v1/common/assistant/wlp/config?intro=1')->assertStatus(403);
        $this->postJson('/api/v1/common/assistant/wlp/messages', ['message' => 'Hello'])->assertStatus(403);
        $this->getJson('/api/v1/common/assistant/wlp/sessions')->assertStatus(403);

        $this->assertDatabaseCount('chat_conversations', 0);

        // A White-Label Partner's own users (partner or staff) do get it.
        foreach ([$this->partnerUser, $this->makeUser(User::ADMIN, ['partner_id' => $this->partner->id])] as $user) {
            Passport::actingAs($user, [], 'api');

            $this->getJson('/api/v1/common/assistant/wlp/config')->assertOk()->assertJsonPath('data.is_ready', true);
            $this->getJson('/api/v1/common/assistant/wlp/sessions')->assertOk();
        }
    }

    /** The agent as OpenAI would return it, with its own welcome and suggested-questions sections. */
    private function mockAgentAndIntro(string $instructions, string $subtitle, array $suggestions): void
    {
        $instructions .= "\n\n## WELCOME MESSAGE\n\n{$subtitle}\n\n## SUGGESTED QUESTIONS\n\n- " . implode("\n- ", $suggestions) . "\n";

        $this->mock(OpenAiAgentsService::class, function ($mock) use ($instructions) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => true, 'error' => null, 'agent' => ['instructions' => $instructions]]);
            // The copy is read from the agent's instructions; no model is ever asked to write it.
            $mock->shouldNotReceive('run');
            $mock->shouldNotReceive('runAgent');
        });
    }
}
