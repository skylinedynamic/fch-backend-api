<?php

namespace Tests\Unit\Settings;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Common\AppConfiguration;
use App\Models\PreQualification\PreQualificationAgentConfig;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The OpenAI settings are the host's: one key, one set of agents, platform-wide.
 *
 * Defect (O22): every reader took the first `openai` row with no partner
 * filter, so a row a non-host partner saved before F34 closed that write - or
 * any such row inserted first - was the key and the agents the platform ran on.
 * The readers now read the host's rows.
 */
class OpenAiSettingsHostScopeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private int $hostId;
    private int $otherId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostId = $this->makeHostPartner()->id;
        $this->otherId = $this->makeWhiteLabelPartner()->id;
    }

    /** The other partner's row first, so an unscoped first() would find it. */
    private function both(string $key, string $theirs, string $hosts): void
    {
        AppConfiguration::set($key, $theirs, AppConfiguration::MODULE_OPENAI, $this->otherId);
        AppConfiguration::set($key, $hosts, AppConfiguration::MODULE_OPENAI, $this->hostId);
    }

    #[Test]
    public function the_api_key_and_models_are_the_hosts(): void
    {
        $this->both('openai_aue_model', 'their-model', 'host-model');

        $setting = new \ReflectionMethod(OpenAiResponsesService::class, 'setting');

        $this->assertSame('host-model', $setting->invoke(null, 'openai_aue_model', 'fallback'));
    }

    #[Test]
    public function the_pre_qualification_agent_is_the_hosts(): void
    {
        $this->both(PreQualificationAgentConfig::TYPE_AGENT . '_agent_id', 'agent-theirs', 'agent-host');

        $this->assertSame('agent-host', PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT)->agent_id);
    }

    #[Test]
    public function the_support_assistants_agent_is_the_hosts(): void
    {
        $this->both(AiAssistantConfig::TYPE_BORROWER_SUPPORT . '_agent_id', 'agent-theirs', 'agent-host');

        $this->assertSame('agent-host', AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->agent_id);
    }

    #[Test]
    public function a_key_saved_only_by_another_partner_does_not_count_as_stored(): void
    {
        AppConfiguration::set('openai_api_key', 'sk-theirs', AppConfiguration::MODULE_OPENAI, $this->otherId);

        $this->assertNull(AppConfiguration::platform('openai_api_key', AppConfiguration::MODULE_OPENAI));
    }
}
