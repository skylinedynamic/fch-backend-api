<?php

namespace App\Console\Commands;

use App\Models\Assistant\AiAssistantConfig;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Builds (or rebuilds) an assistant's OpenAI vector store from the approved
 * knowledge files dropped into storage/app/assistant-knowledge/{type}/, and
 * records the resulting vector_store_id on its AiAssistantConfig row.
 *
 * This is the on-ramp for Phase D: once FCH hands over the real knowledge
 * manifest for an assistant, drop the files in that folder and run this
 * command — no code change needed to go live, only this command plus
 * flipping is_enabled from the admin config screen.
 *
 *   php artisan assistant:sync-knowledge wlp_support
 *   php artisan assistant:sync-knowledge borrower_support
 */
class SyncAssistantKnowledge extends Command
{
    protected $signature = 'assistant:sync-knowledge {type : wlp_support or borrower_support} {--manifest-version= : Manifest version label to record}';

    protected $description = 'Upload an assistant\'s knowledge folder to OpenAI and attach the resulting vector store to its config row';

    public function handle(OpenAiResponsesService $openAi): int
    {
        $type = $this->argument('type');

        if (! in_array($type, AiAssistantConfig::TYPES, true)) {
            $this->error("Unknown assistant type '{$type}'. Expected one of: " . implode(', ', AiAssistantConfig::TYPES));
            return self::FAILURE;
        }

        if (! $openAi->hasApiKey()) {
            $this->error('No OpenAI API key configured (Settings > OpenAI Configuration).');
            return self::FAILURE;
        }

        $folder = storage_path('app/assistant-knowledge/' . $type);
        $files = is_dir($folder) ? glob($folder . '/*') : [];

        if (empty($files)) {
            $this->error("No files found in {$folder}. Drop the approved knowledge manifest there first.");
            return self::FAILURE;
        }

        $apiKey = OpenAiResponsesService::decryptSecret(
            \App\Models\Common\AppConfiguration::get('openai_api_key', '', \App\Models\Common\AppConfiguration::MODULE_OPENAI)
        );

        $this->info('Uploading ' . count($files) . ' file(s)...');
        $fileIds = [];

        foreach ($files as $path) {
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                ->attach('file', file_get_contents($path), basename($path))
                ->post('https://api.openai.com/v1/files', ['purpose' => 'assistants']);

            if (! $response->successful()) {
                $this->error('Failed to upload ' . basename($path) . ': ' . $response->body());
                return self::FAILURE;
            }

            $fileIds[] = $response->json('id');
            $this->line('  uploaded ' . basename($path));
        }

        $this->info('Creating vector store...');

        $storeResponse = Http::withHeaders(['Authorization' => 'Bearer ' . $apiKey])
            ->post('https://api.openai.com/v1/vector_stores', [
                'name' => "fch-assistant-{$type}",
                'file_ids' => $fileIds,
            ]);

        if (! $storeResponse->successful()) {
            $this->error('Failed to create vector store: ' . $storeResponse->body());
            return self::FAILURE;
        }

        $vectorStoreId = $storeResponse->json('id');

        $config = AiAssistantConfig::find($type);
        $config->vector_store_id = $vectorStoreId;
        $config->knowledge_manifest_version = $this->option('manifest-version');
        $config->save();

        $this->info("Done. vector_store_id={$vectorStoreId} attached to '{$type}'.");
        $this->comment("Remember: this assistant still needs its system prompt file populated (app/Services/Assistant/{$type}_system_prompt.txt) and is_enabled=true from the admin Assistant Config screen before it goes live.");

        return self::SUCCESS;
    }
}
