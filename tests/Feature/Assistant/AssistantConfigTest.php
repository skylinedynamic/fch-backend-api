<?php

namespace Tests\Feature\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin Assistant Config screen: host-only, read-only status showing
 * which OpenAI agent each assistant runs and what that agent currently says
 * on the platform.
 */
class AssistantConfigTest extends TestCase
{
    use RefreshDatabase;

    private Partner $hostPartner;
    private Partner $partner;
    private User $hostUser;
    private User $partnerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostPartner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);

        $this->hostUser = $this->makeUser(['partner_id' => $this->hostPartner->id, 'entity_type' => Partner::class, 'entity_id' => $this->hostPartner->id]);
        $this->partnerUser = $this->makeUser(['partner_id' => $this->partner->id, 'entity_type' => Partner::class, 'entity_id' => $this->partner->id]);
    }

    private function makeUser(array $attributes = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return User::findOrFail($id);
    }

    #[Test]
    public function a_non_host_partner_cannot_view_assistant_config(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->getJson('/api/v1/common/assistant-config')->assertStatus(403);
    }

    #[Test]
    public function a_host_user_sees_each_agent_as_it_currently_is_on_the_platform(): void
    {
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['agent_id' => 'agent_wlp_test']);

        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => true, 'error' => null, 'agent' => [
                'name' => 'FCH WLP Support Assistant',
                'model' => 'gpt-6-astra',
                'reasoning' => ['effort' => 'medium'],
            ]]);
        });

        Passport::actingAs($this->hostUser, [], 'api');

        $this->getJson('/api/v1/common/assistant-config')
            ->assertSuccessful()
            ->assertJsonFragment([
                'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
                'agent_id' => 'agent_wlp_test',
                'is_ready' => true,
            ])
            ->assertJsonFragment(['name' => 'FCH WLP Support Assistant', 'model' => 'gpt-6-astra', 'reasoning_effort' => 'medium']);
    }

    #[Test]
    public function an_unreadable_agent_is_reported_instead_of_failing_the_screen(): void
    {
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['agent_id' => 'agent_wlp_test']);

        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => false, 'agent' => null, 'error' => 'OpenAI Agents API error (403): missing api.agents.read']);
        });

        Passport::actingAs($this->hostUser, [], 'api');

        $this->getJson('/api/v1/common/assistant-config')
            ->assertSuccessful()
            ->assertJsonFragment(['agent' => null, 'agent_error' => 'OpenAI Agents API error (403): missing api.agents.read']);
    }

    #[Test]
    public function the_host_saves_and_clears_each_assistants_agent_id_from_settings(): void
    {
        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => true, 'error' => null, 'agent' => ['name' => 'Agent']]);
        });

        Passport::actingAs($this->hostUser, [], 'api');
        $url = '/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT;

        $this->putJson($url, ['agent_id' => 'agent_490bca14c956'])->assertOk();
        $this->assertSame('agent_490bca14c956', AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->agent_id);
        $this->assertNull(AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->agent_id);

        $this->getJson('/api/v1/common/assistant-config')
            ->assertJsonFragment(['assistant_type' => AiAssistantConfig::TYPE_BORROWER_SUPPORT, 'agent_id' => 'agent_490bca14c956', 'is_ready' => true]);

        $this->putJson('/api/v1/common/assistant-config/unknown_type', ['agent_id' => 'agent_x1'])->assertStatus(404);

        // Disabling keeps the agent id, so it can simply be switched back on.
        $this->putJson($url, ['agent_id' => 'agent_490bca14c956', 'enabled' => false])->assertOk();
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $this->assertSame('agent_490bca14c956', $config->agent_id);
        $this->assertFalse($config->is_enabled);
        $this->assertFalse($config->isReady());

        $this->putJson($url, ['agent_id' => 'agent_490bca14c956', 'enabled' => true])->assertOk();
        $this->assertTrue(AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->isReady());

        // The id may be cleared only while the assistant is disabled.
        $this->putJson($url, ['agent_id' => null, 'enabled' => false])->assertOk();
        $this->assertNull(AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->agent_id);
    }

    #[Test]
    public function saving_validates_the_agent_id_and_never_stores_a_bad_one(): void
    {
        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->with('agent_missing1')
                ->andReturn(['ok' => false, 'agent' => null, 'error' => "OpenAI Agents API error (404): No agent found with id 'agent_missing1'."]);
        });

        Passport::actingAs($this->hostUser, [], 'api');
        $url = '/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT;

        // wrong format
        $this->putJson($url, ['agent_id' => 'pmpt_123', 'enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['agent_id']);
        // required while enabled
        $this->putJson($url, ['agent_id' => null, 'enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['agent_id']);
        // well-formed, but OpenAI does not know it
        $response = $this->putJson($url, ['agent_id' => 'agent_missing1', 'enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['agent_id']);
        $this->assertStringContainsString('No agent found', json_encode($response->json()));

        $this->assertNull(AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->agent_id);
    }

    #[Test]
    public function the_host_saves_the_vector_stores_each_assistant_searches(): void
    {
        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => true, 'error' => null, 'agent' => ['name' => 'Agent']]);
            $mock->shouldReceive('retrieveVectorStore')->andReturn(['ok' => true, 'error' => null, 'store' => ['id' => 'vs_x']]);
        });

        Passport::actingAs($this->hostUser, [], 'api');
        $url = '/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT;

        $stores = [
            ['id' => 'vs_6abe4d0caac08191960c116f6681bc27', 'enabled' => true],
            // Added now, switched on later: the id is kept either way.
            ['id' => 'vs_6abe0f46ad8c8191b55186af0a98a070', 'enabled' => false],
        ];

        $this->putJson($url, ['agent_id' => 'agent_490bca14c956', 'vector_stores' => $stores])->assertOk();

        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $this->assertSame($stores, $config->vector_stores);
        // Only the switched-on store is ever handed to the agent.
        $this->assertSame(['vs_6abe4d0caac08191960c116f6681bc27'], $config->enabledVectorStoreIds());

        // Saving one assistant's stores never touches the other's.
        $this->assertSame([], AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->vector_stores);

        $this->getJson('/api/v1/common/assistant-config')
            ->assertJsonFragment(['vector_stores' => $stores]);

        // Leaving the field out keeps what is stored, rather than clearing it.
        $this->putJson($url, ['agent_id' => 'agent_490bca14c956'])->assertOk();
        $this->assertCount(2, AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->vector_stores);

        // An empty list is how a host deliberately clears them.
        $this->putJson($url, ['agent_id' => 'agent_490bca14c956', 'vector_stores' => []])->assertOk();
        $this->assertSame([], AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->vector_stores);
    }

    #[Test]
    public function the_same_store_added_twice_is_kept_once_so_it_cannot_be_half_on(): void
    {
        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => true, 'error' => null, 'agent' => ['name' => 'Agent']]);
            $mock->shouldReceive('retrieveVectorStore')->andReturn(['ok' => true, 'error' => null, 'store' => ['id' => 'vs_x']]);
        });

        Passport::actingAs($this->hostUser, [], 'api');

        $this->putJson('/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_WLP_SUPPORT, [
            'agent_id' => 'agent_490bca14c956',
            'vector_stores' => [
                ['id' => 'vs_duplicate', 'enabled' => true],
                ['id' => '', 'enabled' => true],
                ['id' => 'vs_duplicate', 'enabled' => false],
            ],
        ])->assertOk();

        // The first row wins; the blank row is dropped rather than refused, so
        // an empty repeater row the host never filled in is not an error.
        $this->assertSame(
            [['id' => 'vs_duplicate', 'enabled' => true]],
            AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->vector_stores
        );
    }

    #[Test]
    public function a_vector_store_openai_cannot_load_is_refused_rather_than_saved(): void
    {
        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => true, 'error' => null, 'agent' => ['name' => 'Agent']]);
            $mock->shouldReceive('retrieveVectorStore')->with('vs_typo')
                ->andReturn(['ok' => false, 'store' => null, 'error' => 'OpenAI API error (404): No such vector store: vs_typo']);
        });

        Passport::actingAs($this->hostUser, [], 'api');
        $url = '/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT;

        // Wrong prefix: an agent or file id pasted into the wrong field.
        $this->putJson($url, [
            'agent_id' => 'agent_490bca14c956',
            'vector_stores' => [['id' => 'file_9SgkPtn1ygGMwJC9nC7G3o', 'enabled' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors(['vector_stores.0.id']);

        // Well-formed, but this key cannot reach it — caught here, not in chat.
        $response = $this->putJson($url, [
            'agent_id' => 'agent_490bca14c956',
            'vector_stores' => [['id' => 'vs_typo', 'enabled' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors(['vector_stores.0.id']);
        $this->assertStringContainsString('No such vector store', json_encode($response->json()));

        // A switched-off store is checked too: a typo parked today would
        // otherwise only surface in front of a user when it is switched on.
        $this->putJson($url, [
            'agent_id' => 'agent_490bca14c956',
            'vector_stores' => [['id' => 'vs_typo', 'enabled' => false]],
        ])->assertStatus(422)->assertJsonValidationErrors(['vector_stores.0.id']);

        $this->assertSame([], AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->vector_stores);
    }

    #[Test]
    public function settings_still_save_when_openai_cannot_be_reached_to_verify_them(): void
    {
        // A connection failure says nothing about whether the ids are right.
        // Refusing on one would leave the host unable to save at all whenever
        // the link to OpenAI is down — including to correct a bad id.
        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $unreachable = ['ok' => false, 'unreachable' => true, 'error' => 'Could not reach OpenAI: cURL error 28: Connection timed out'];
            $mock->shouldReceive('retrieveAgent')->andReturn($unreachable + ['agent' => null]);
            $mock->shouldReceive('retrieveVectorStore')->andReturn($unreachable + ['store' => null]);
        });

        Passport::actingAs($this->hostUser, [], 'api');

        $response = $this->putJson('/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT, [
            'agent_id' => 'agent_490bca14c956',
            'vector_stores' => [['id' => 'vs_knowledge', 'enabled' => true]],
        ])->assertOk();

        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $this->assertSame('agent_490bca14c956', $config->agent_id);
        $this->assertSame(['vs_knowledge'], $config->enabledVectorStoreIds());

        // Saved, but never silently: the host is told what went unverified.
        $this->assertStringContainsString('could not be reached to verify', (string) $response->json('message'));
    }

    #[Test]
    public function more_stores_than_an_assistant_may_hold_are_refused(): void
    {
        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => true, 'error' => null, 'agent' => ['name' => 'Agent']]);
            $mock->shouldReceive('retrieveVectorStore')->andReturn(['ok' => true, 'error' => null, 'store' => ['id' => 'vs_x']]);
        });

        Passport::actingAs($this->hostUser, [], 'api');

        $tooMany = [];
        for ($i = 0; $i <= AiAssistantConfig::MAX_VECTOR_STORES; $i++) {
            $tooMany[] = ['id' => 'vs_store' . $i, 'enabled' => true];
        }

        $this->putJson('/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT, [
            'agent_id' => 'agent_490bca14c956',
            'vector_stores' => $tooMany,
        ])->assertStatus(422)->assertJsonValidationErrors(['vector_stores']);

        $this->assertSame([], AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->vector_stores);
    }

    #[Test]
    public function the_screen_shows_the_vector_stores_the_saved_agent_itself_carries(): void
    {
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['agent_id' => 'agent_wlp_test']);

        $this->mock(OpenAiAgentsService::class, function ($mock) {
            $mock->shouldReceive('retrieveAgent')->andReturn(['ok' => true, 'error' => null, 'agent' => [
                'name' => 'FCH WLP Support Assistant',
                'model' => 'gpt-6-astra',
                'tools' => [
                    ['type' => 'web_search'],
                    ['type' => 'file_search', 'vector_store_ids' => ['vs_6abe0f46ad8c8191b55186af0a98a070']],
                ],
            ]]);
        });

        Passport::actingAs($this->hostUser, [], 'api');

        // So a host can see at a glance when the platform agent and these
        // settings point at different knowledge bases.
        $this->getJson('/api/v1/common/assistant-config')
            ->assertSuccessful()
            ->assertJsonFragment(['agent_vector_store_ids' => ['vs_6abe0f46ad8c8191b55186af0a98a070']]);
    }

    #[Test]
    public function a_non_host_partner_cannot_change_an_assistants_agent(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->putJson('/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT, ['agent_id' => 'agent_x1'])->assertStatus(403);
        $this->assertNull(AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->agent_id);
    }
}
