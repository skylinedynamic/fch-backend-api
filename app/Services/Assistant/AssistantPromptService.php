<?php

namespace App\Services\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\AiAssistantPromptVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Prompt-only release management for the WLP / Borrower assistants.
 *
 * A routine approved wording/behaviour change is: create a new version, then
 * activate it. No code deploy, no file edit. Rolling back is activating an
 * older version. Versions are immutable once created (a fix is a new version),
 * so the (assistant_type, version, sha256) stamped on every reply identifies
 * the exact text that produced it.
 *
 * Anything that changes application behaviour — route mappings, navigation
 * fields, response schema, tools, auth/RBAC — is NOT a prompt change and
 * still goes through code (config/assistant.php 'navigation' etc).
 */
class AssistantPromptService
{
    public function assertType(string $assistantType): void
    {
        if (! in_array($assistantType, AiAssistantConfig::TYPES, true)) {
            throw new InvalidArgumentException("Unknown assistant type '{$assistantType}'.");
        }
    }

    public function create(string $assistantType, string $version, string $content, ?string $notes = null, ?int $userId = null): AiAssistantPromptVersion
    {
        $this->assertType($assistantType);

        $content = str_replace("\r\n", "\n", trim($content)) . "\n";
        $version = trim($version);

        if (! preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,39}$/', $version)) {
            throw new InvalidArgumentException('Version must be 1-40 characters: letters, digits, dot, dash, underscore.');
        }

        if (strlen($content) < 200) {
            throw new InvalidArgumentException('Prompt content is too short to be a complete approved prompt.');
        }

        if (AiAssistantPromptVersion::where('assistant_type', $assistantType)->where('version', $version)->exists()) {
            throw new InvalidArgumentException("Version {$version} already exists for {$assistantType}. Versions are immutable; publish a new version number.");
        }

        return AiAssistantPromptVersion::create([
            'assistant_type' => $assistantType,
            'version' => $version,
            'content' => $content,
            'content_sha256' => hash('sha256', $content),
            'notes' => $notes,
            'created_by' => $userId,
        ])->refresh();
    }

    /** Makes exactly one version active for its assistant (also the rollback path). */
    public function activate(AiAssistantPromptVersion $version, ?int $userId = null): AiAssistantPromptVersion
    {
        return DB::transaction(function () use ($version, $userId) {
            AiAssistantPromptVersion::where('assistant_type', $version->assistant_type)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            // Direct query, not $version->save(): a stale in-memory model (e.g. a
            // version activated earlier, then superseded) would look unchanged and
            // silently skip the write, breaking rollback.
            AiAssistantPromptVersion::whereKey($version->getKey())->update([
                'is_active' => true,
                'activated_at' => now(),
                'activated_by' => $userId,
            ]);

            return $version->refresh();
        });
    }

    /** Reverts to the bundled, code-shipped prompt by deactivating every database version. */
    public function revertToBundled(string $assistantType): void
    {
        $this->assertType($assistantType);

        AiAssistantPromptVersion::where('assistant_type', $assistantType)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /** Version list (without content), newest first. */
    public function list(string $assistantType)
    {
        $this->assertType($assistantType);

        return AiAssistantPromptVersion::where('assistant_type', $assistantType)
            ->latest('id')
            ->get(['id', 'assistant_type', 'version', 'content_sha256', 'notes', 'is_active', 'activated_at', 'activated_by', 'created_by', 'created_at']);
    }
}
