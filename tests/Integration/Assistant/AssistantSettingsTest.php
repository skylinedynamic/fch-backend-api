<?php

namespace Tests\Integration\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Common\AppConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where an assistant's settings come from: nothing is built into the code.
 * The agent id and the on/off switch are whatever a host saved in Settings.
 */
class AssistantSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_fresh_install_has_no_agent_and_is_not_ready(): void
    {
        foreach (AiAssistantConfig::TYPES as $type) {
            $config = AiAssistantConfig::find($type);

            $this->assertNull($config->agent_id);
            $this->assertFalse($config->isReady());
        }
    }

    #[Test]
    public function the_agent_id_is_whatever_was_last_saved_and_blank_clears_it(): void
    {
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_BORROWER_SUPPORT, ['agent_id' => ' agent_abc ']);

        $this->assertSame('agent_abc', AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->agent_id);
        $this->assertNull(AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->agent_id, 'each assistant has its own agent');

        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_BORROWER_SUPPORT, ['agent_id' => '']);

        $this->assertFalse(AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->isReady());
    }

    #[Test]
    public function the_agent_id_is_encrypted_in_the_database_and_decrypted_when_read(): void
    {
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_BORROWER_SUPPORT, ['agent_id' => 'agent_490bca14c9564d4b']);

        $stored = DB::table('app_configurations')->where('key', 'borrower_support_agent_id')->value('value');

        $this->assertNotSame('agent_490bca14c9564d4b', $stored);
        $this->assertStringNotContainsString('agent_', $stored);
        $this->assertSame('agent_490bca14c9564d4b', Crypt::decryptString($stored));
        $this->assertSame('agent_490bca14c9564d4b', AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT)->agent_id);
    }

    #[Test]
    public function an_agent_id_saved_before_encryption_was_added_still_works(): void
    {
        AppConfiguration::set('wlp_support_agent_id', 'agent_plain123', AppConfiguration::MODULE_OPENAI);

        $this->assertSame('agent_plain123', AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->agent_id);
    }

    #[Test]
    public function an_assistant_is_on_by_default_and_the_switch_keeps_its_agent_id(): void
    {
        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['agent_id' => 'agent_abc']);
        $this->assertTrue(AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->isReady());

        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['enabled' => false]);

        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT);
        $this->assertFalse($config->isReady());
        $this->assertSame('agent_abc', $config->agent_id);

        AiAssistantConfig::saveSettings(AiAssistantConfig::TYPE_WLP_SUPPORT, ['enabled' => true]);
        $this->assertTrue(AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->isReady());
    }
}
