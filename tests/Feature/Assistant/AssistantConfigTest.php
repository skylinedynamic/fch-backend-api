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
    public function a_non_host_partner_cannot_change_an_assistants_agent(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->putJson('/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT, ['agent_id' => 'agent_x1'])->assertStatus(403);
        $this->assertNull(AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->agent_id);
    }
}
