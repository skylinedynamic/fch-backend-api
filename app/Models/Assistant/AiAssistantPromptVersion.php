<?php

namespace App\Models\Assistant;

use Illuminate\Database\Eloquent\Model;

/**
 * One immutable, approved version of an assistant's system prompt. See
 * AiAssistantConfig::activePrompt() for how the active row is resolved and
 * the migration for the immutability/rollback contract.
 */
class AiAssistantPromptVersion extends Model
{
    protected $table = 'ai_assistant_prompt_versions';

    protected $fillable = [
        'assistant_type',
        'version',
        'content',
        'content_sha256',
        'notes',
        'is_active',
        'activated_at',
        'activated_by',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
    ];

    /** Never serialise the full prompt into list responses; use ->content explicitly. */
    protected $hidden = ['content'];

    /**
     * Extracts a version label ("1.3.2", "1.5") from a prompt's header line,
     * e.g. "... SYSTEM PROMPT v1.3.2" / "... INSTRUCTIONS v1.5".
     */
    public static function detectVersion(string $content): ?string
    {
        $head = substr($content, 0, 400);

        return preg_match('/\bv(\d+(?:\.\d+)+)\b/i', $head, $m) ? $m[1] : null;
    }
}
