<?php

namespace App\Console\Commands;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\AiAssistantPromptVersion;
use App\Services\Assistant\AssistantPromptService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Prompt release management from the shell (same operations as the host-only
 * API). Examples:
 *
 *   php artisan assistant:prompt status
 *   php artisan assistant:prompt import wlp_support path/to/prompt.txt --release=1.3.3 --activate
 *   php artisan assistant:prompt activate borrower_support 1.5      # also the rollback command
 *   php artisan assistant:prompt bundled wlp_support                # revert to the code-shipped prompt
 */
class AssistantPrompt extends Command
{
    protected $signature = 'assistant:prompt {action : status|import|activate|bundled} {type? : wlp_support or borrower_support} {arg? : file path (import) or version (activate)} {--release= : Version label for import (default: detected from the prompt header)} {--notes= : Release note} {--activate : Activate immediately after import}';

    protected $description = 'Manage versioned WLP / Borrower assistant system prompts (import, activate, roll back, status)';

    public function handle(AssistantPromptService $prompts): int
    {
        $action = $this->argument('action');

        try {
            if ($action === 'status') {
                return $this->status($prompts);
            }

            $type = (string) $this->argument('type');
            $prompts->assertType($type);

            return match ($action) {
                'import' => $this->import($prompts, $type),
                'activate' => $this->activate($prompts, $type),
                'bundled' => $this->bundled($prompts, $type),
                default => $this->failWith("Unknown action '{$action}'."),
            };
        } catch (InvalidArgumentException $e) {
            return $this->failWith($e->getMessage());
        }
    }

    private function status(AssistantPromptService $prompts): int
    {
        foreach (AiAssistantConfig::TYPES as $type) {
            $active = AiAssistantConfig::find($type)->activePrompt();
            $this->info("{$type}: v{$active['version']} ({$active['source']}) sha256=" . substr($active['sha256'], 0, 16));

            foreach ($prompts->list($type) as $v) {
                $this->line(sprintf('   %s v%s  %s  %s', $v->is_active ? '*' : ' ', $v->version, substr($v->content_sha256, 0, 12), $v->notes ?? ''));
            }
        }

        return self::SUCCESS;
    }

    private function import(AssistantPromptService $prompts, string $type): int
    {
        $path = (string) $this->argument('arg');

        if ($path === '' || ! is_file($path)) {
            return $this->failWith('Provide the path of the prompt text file to import.');
        }

        $content = (string) file_get_contents($path);
        $version = $this->option('release') ?: AiAssistantPromptVersion::detectVersion($content);

        if (! $version) {
            return $this->failWith('Could not detect a version from the prompt header; pass --release=.');
        }

        $row = $prompts->create($type, $version, $content, $this->option('notes'));
        $this->info("Imported {$type} v{$row->version} sha256=" . substr($row->content_sha256, 0, 16));

        if ($this->option('activate')) {
            $prompts->activate($row);
            $this->info('Activated. It is used from the next message; no deploy needed.');
        } else {
            $this->comment("Not active yet. Run: php artisan assistant:prompt activate {$type} {$row->version}");
        }

        return self::SUCCESS;
    }

    private function activate(AssistantPromptService $prompts, string $type): int
    {
        $version = AiAssistantPromptVersion::where('assistant_type', $type)->where('version', (string) $this->argument('arg'))->first();

        if (! $version) {
            return $this->failWith('No such version. Run `assistant:prompt status` to list them.');
        }

        $prompts->activate($version);
        $this->info("{$type} is now on v{$version->version}.");

        return self::SUCCESS;
    }

    private function bundled(AssistantPromptService $prompts, string $type): int
    {
        $prompts->revertToBundled($type);
        $this->info("{$type} reverted to the bundled prompt shipped with the code.");

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
