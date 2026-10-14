<?php

namespace Tests\Integration\Console;

use App\Console\Commands\VerifyAssistantKnowledge;
use App\Models\Assistant\AiAssistantConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The operator check that each support assistant can ground its answers: its
 * agent can be read, and it has a knowledge base (a vector store) to search.
 * Without --live it only reads settings - no chat turn is ever sent. OpenAI
 * is faked.
 */
#[CoversClass(VerifyAssistantKnowledge::class)]
class VerifyAssistantKnowledgeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const AGENTS = 'https://api.openai.com/v1/agents/';
    private const STORES = 'https://api.openai.com/v1/vector_stores/';

    /** @param array<string, int> $storeFiles vector store id => indexed file count */
    private function fakeOpenAi(array $agentStores = [], array $storeFiles = [], int $agentStatus = 200): void
    {
        Http::fake(function (Request $request) use ($agentStores, $storeFiles, $agentStatus) {
            if (str_starts_with($request->url(), self::AGENTS)) {
                return $agentStatus === 200
                    ? Http::response(['id' => 'agent_x', 'model' => 'gpt-6-astra', 'tools' => $agentStores ? [['type' => 'file_search', 'vector_store_ids' => $agentStores]] : []])
                    : Http::response(['error' => ['message' => 'No agent found']], $agentStatus);
            }
            if (str_starts_with($request->url(), self::STORES)) {
                $id = substr($request->url(), strlen(self::STORES));

                return isset($storeFiles[$id])
                    ? Http::response(['id' => $id, 'name' => 'Funding knowledge', 'file_counts' => ['completed' => $storeFiles[$id]]])
                    : Http::response(['error' => ['message' => 'No vector store found']], 404);
            }

            return Http::response(['error' => ['message' => 'unexpected call']], 500);
        });
    }

    private function configureBoth(array $stores): void
    {
        foreach (AiAssistantConfig::TYPES as $type) {
            AiAssistantConfig::saveSettings($type, ['agent_id' => "agent_{$type}", 'vector_stores' => $stores]);
        }
    }

    private function noChatTurnWasSent(): void
    {
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/responses'));
    }

    #[Test]
    public function assistants_with_a_readable_agent_and_a_searchable_store_pass(): void
    {
        $this->fakeOpenAi(storeFiles: ['vs_kb1' => 12]);
        $this->configureBoth([['id' => 'vs_kb1', 'enabled' => true]]);

        $this->artisan('fch:verify-assistant-knowledge')->assertSuccessful();

        $this->noChatTurnWasSent();
    }

    #[Test]
    public function an_assistant_with_no_agent_saved_fails_without_calling_openai(): void
    {
        $this->fakeOpenAi();

        $this->artisan('fch:verify-assistant-knowledge')->assertFailed();

        Http::assertNothingSent();
    }

    #[Test]
    public function an_agent_openai_cannot_find_fails(): void
    {
        $this->fakeOpenAi(storeFiles: ['vs_kb1' => 12], agentStatus: 404);
        $this->configureBoth([['id' => 'vs_kb1', 'enabled' => true]]);

        $this->artisan('fch:verify-assistant-knowledge')->assertFailed();
    }

    #[Test]
    public function a_store_openai_cannot_find_fails(): void
    {
        $this->fakeOpenAi();
        $this->configureBoth([['id' => 'vs_gone', 'enabled' => true]]);

        $this->artisan('fch:verify-assistant-knowledge')->assertFailed();
    }

    #[Test]
    public function no_store_anywhere_fails(): void
    {
        $this->fakeOpenAi();
        $this->configureBoth([]);

        $this->artisan('fch:verify-assistant-knowledge')->assertFailed();
    }

    #[Test]
    public function the_agents_own_store_is_enough_without_one_in_settings(): void
    {
        $this->fakeOpenAi(agentStores: ['vs_agent']);
        $this->configureBoth([]);

        $this->artisan('fch:verify-assistant-knowledge')->assertSuccessful();
    }

    #[Test]
    public function every_store_switched_off_fails_unless_the_agent_has_its_own(): void
    {
        $this->fakeOpenAi(storeFiles: ['vs_kb1' => 12]);
        $this->configureBoth([['id' => 'vs_kb1', 'enabled' => false]]);

        $this->artisan('fch:verify-assistant-knowledge')->assertFailed();
    }

    /**
     * Defect (O49): a switched-on store with nothing indexed was only warned
     * about and the check passed - although, as the command itself says, it is
     * the quietest failure of all: every search runs and finds nothing.
     */
    #[Test]
    public function an_enabled_store_with_no_indexed_files_fails_the_check(): void
    {
        $this->fakeOpenAi(storeFiles: ['vs_empty' => 0]);
        $this->configureBoth([['id' => 'vs_empty', 'enabled' => true]]);

        $this->artisan('fch:verify-assistant-knowledge')
            ->expectsOutputToContain('has no indexed files')
            ->assertFailed();
    }

    #[Test]
    public function a_switched_off_empty_store_does_not_fail_the_check(): void
    {
        $this->fakeOpenAi(storeFiles: ['vs_full' => 3, 'vs_empty' => 0]);
        $this->configureBoth([['id' => 'vs_full', 'enabled' => true], ['id' => 'vs_empty', 'enabled' => false]]);

        $this->artisan('fch:verify-assistant-knowledge')->assertSuccessful();
    }
}
