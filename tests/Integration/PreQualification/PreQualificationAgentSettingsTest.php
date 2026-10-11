<?php

namespace Tests\Integration\PreQualification;

use App\Models\Common\AppConfiguration;
use App\Models\PreQualification\PreQualificationAgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where each Pre-Qualification step's agent and knowledge base come from:
 * nothing is built into the code. The agent id and the storage ids are
 * whatever a host saved in Settings > OpenAI Configuration.
 */
class PreQualificationAgentSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_fresh_install_has_no_agent_so_no_step_can_evaluate(): void
    {
        foreach (PreQualificationAgentConfig::TYPES as $step) {
            $config = PreQualificationAgentConfig::find($step);

            $this->assertNull($config->agent_id);
            $this->assertFalse($config->isReady(), "{$step} must not claim to be ready without an agent");

            // Reported as switched off, not merely unready. The Settings screen
            // marks the Agent ID required for a step that is on, so a step
            // reporting itself on with no agent would fail validation on a
            // fresh install and make the whole OpenAI screen — API key
            // included — impossible to save.
            $this->assertFalse($config->is_enabled, "{$step} must not be switched on before it has an agent");
        }
    }

    #[Test]
    public function saving_an_agent_switches_its_step_on_without_a_separate_toggle(): void
    {
        PreQualificationAgentConfig::saveSettings(PreQualificationAgentConfig::TYPE_AGENT, ['agent_id' => 'agent_abc']);

        $config = PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT);

        $this->assertTrue($config->is_enabled);
        $this->assertTrue($config->isReady());
    }

    #[Test]
    public function a_step_switched_off_by_hand_stays_off_even_with_an_agent_saved(): void
    {
        $step = PreQualificationAgentConfig::TYPE_AGENT;

        PreQualificationAgentConfig::saveSettings($step, ['agent_id' => 'agent_abc', 'enabled' => false]);

        $config = PreQualificationAgentConfig::find($step);

        $this->assertFalse($config->is_enabled, 'an explicit switch must outrank the agent being present');
        $this->assertFalse($config->isReady());
        $this->assertSame('agent_abc', $config->agent_id);
    }

    #[Test]
    public function there_is_exactly_one_agent_to_configure(): void
    {
        // One agent underwrites a pre-qualification: it produces the internal
        // evaluation, the borrower's result is the borrower-safe part of that
        // same evaluation, and whether the answers are usable is its own
        // data_status. One ID to paste, one place to edit.
        $this->assertSame(['prequalification_agent'], PreQualificationAgentConfig::TYPES);

        PreQualificationAgentConfig::saveSettings(
            PreQualificationAgentConfig::TYPE_AGENT,
            ['agent_id' => ' agent_5fdfc7c079ef427093ae9ca4535c8e98d9f45928981f44b491 ']
        );

        $this->assertSame(
            'agent_5fdfc7c079ef427093ae9ca4535c8e98d9f45928981f44b491',
            PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT)->agent_id
        );
    }

    #[Test]
    public function the_agent_id_is_encrypted_in_the_database_and_decrypted_when_read(): void
    {
        PreQualificationAgentConfig::saveSettings(
            PreQualificationAgentConfig::TYPE_AGENT,
            ['agent_id' => 'agent_490bca14c9564d4b']
        );

        $stored = DB::table('app_configurations')->where('key', 'prequalification_agent_agent_id')->value('value');

        $this->assertNotSame('agent_490bca14c9564d4b', $stored);
        $this->assertStringNotContainsString('agent_', $stored);
        $this->assertSame('agent_490bca14c9564d4b', Crypt::decryptString($stored));
        $this->assertSame('agent_490bca14c9564d4b', PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT)->agent_id);
    }

    #[Test]
    public function an_agent_id_stored_unencrypted_still_works(): void
    {
        AppConfiguration::set('prequalification_agent_agent_id', 'agent_plain123', AppConfiguration::MODULE_OPENAI);

        $this->assertSame('agent_plain123', PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT)->agent_id);
    }

    #[Test]
    public function switching_a_step_off_keeps_its_agent_id_so_it_can_be_switched_back_on(): void
    {
        $step = PreQualificationAgentConfig::TYPE_AGENT;

        PreQualificationAgentConfig::saveSettings($step, ['agent_id' => 'agent_abc']);
        $this->assertTrue(PreQualificationAgentConfig::find($step)->isReady());

        PreQualificationAgentConfig::saveSettings($step, ['enabled' => false]);

        $config = PreQualificationAgentConfig::find($step);
        $this->assertFalse($config->isReady());
        $this->assertSame('agent_abc', $config->agent_id);

        PreQualificationAgentConfig::saveSettings($step, ['enabled' => true]);
        $this->assertTrue(PreQualificationAgentConfig::find($step)->isReady());
    }

    #[Test]
    public function every_switched_on_storage_id_is_searched_together_in_one_tool(): void
    {
        PreQualificationAgentConfig::saveSettings(PreQualificationAgentConfig::TYPE_AGENT, [
            'agent_id' => 'agent_abc',
            'vector_stores' => [
                ['id' => 'vs_6abe4d0caac08191960c116f6681bc27', 'enabled' => true],
                ['id' => 'vs_6abe0f46ad8c8191b55186af0a98a070', 'enabled' => true],
                // Parked while it is rebuilt: its id is kept, but it is not searched.
                ['id' => 'vs_parkedwhilerebuilt0000000000000', 'enabled' => false],
            ],
        ]);

        $config = PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT);

        $this->assertCount(3, $config->vector_stores, 'a parked store keeps its id in settings');
        $this->assertSame(
            ['vs_6abe4d0caac08191960c116f6681bc27', 'vs_6abe0f46ad8c8191b55186af0a98a070'],
            $config->enabledVectorStoreIds(),
            'only the switched-on stores are searched, in the order the host arranged them'
        );

        // Several stores go in one file_search tool, so an evaluation is
        // grounded in whichever of them holds the passage.
        $this->assertSame([
            'type' => 'file_search',
            'vector_store_ids' => ['vs_6abe4d0caac08191960c116f6681bc27', 'vs_6abe0f46ad8c8191b55186af0a98a070'],
            'max_num_results' => 20,
        ], $config->fileSearchTool());
    }

    #[Test]
    public function the_knowledge_base_is_searched_before_the_step_is_allowed_to_answer(): void
    {
        config(['openai.file_search.force' => true]);

        PreQualificationAgentConfig::saveSettings(PreQualificationAgentConfig::TYPE_AGENT, [
            'agent_id' => 'agent_abc',
            'vector_stores' => [['id' => 'vs_abc123', 'enabled' => true]],
        ]);

        $overrides = PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT)->agentOverrides();

        $this->assertSame(['type' => 'file_search'], $overrides['tool_choice']);
        $this->assertSame(['vs_abc123'], $overrides['tools'][0]['vector_store_ids']);
    }

    #[Test]
    public function a_step_with_no_storage_configured_leaves_the_saved_agents_own_tools_alone(): void
    {
        PreQualificationAgentConfig::saveSettings(PreQualificationAgentConfig::TYPE_AGENT, ['agent_id' => 'agent_abc']);

        $config = PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT);

        $this->assertNull($config->fileSearchTool());
        $this->assertSame([], $config->agentOverrides());
    }

    #[Test]
    public function every_storage_id_switched_off_is_the_same_as_none_configured(): void
    {
        PreQualificationAgentConfig::saveSettings(PreQualificationAgentConfig::TYPE_AGENT, [
            'agent_id' => 'agent_abc',
            'vector_stores' => [['id' => 'vs_abc123', 'enabled' => false]],
        ]);

        $config = PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT);

        $this->assertSame([], $config->enabledVectorStoreIds());
        $this->assertSame([], $config->agentOverrides(), 'nothing is forced when there is nothing to search');
    }

    #[Test]
    public function a_repeated_storage_id_is_kept_once_so_it_cannot_be_half_on_and_half_off(): void
    {
        PreQualificationAgentConfig::saveSettings(PreQualificationAgentConfig::TYPE_AGENT, [
            'agent_id' => 'agent_abc',
            'vector_stores' => [
                ['id' => 'vs_abc123', 'enabled' => true],
                ['id' => 'vs_abc123', 'enabled' => false],
                ['id' => '', 'enabled' => true],
            ],
        ]);

        $config = PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT);

        $this->assertSame([['id' => 'vs_abc123', 'enabled' => true]], $config->vector_stores);
    }

    #[Test]
    public function the_pipeline_ships_no_prompt_or_schema_of_its_own(): void
    {
        // The instructions, the output schema, the model and the reasoning
        // effort all live on the agent. A prompt or schema file reappearing
        // here would mean a borrower could be underwritten by instructions
        // nobody edited on the platform — and with no way to tell which of the
        // two had run.
        $directory = app_path('Services/FCH/Common/PreQualification');
        $strays = glob($directory . '/*/*.{txt,json}', GLOB_BRACE) ?: [];

        $this->assertSame([], array_map('basename', $strays), "{$directory} must contain no prompt or schema files");
    }
}
