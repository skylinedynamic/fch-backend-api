<?php

namespace App\Models\Assistant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    protected $fillable = [
        'uuid',
        'conversation_id',
        'role',
        'content',
        'actions',
        'sources',
        'openai_request_id',
        'usage',
        'prompt_version',
        'prompt_sha256',
        'prompt_source',
        'navigation',
    ];

    protected $casts = [
        'actions' => 'array',
        'sources' => 'array',
        'usage' => 'array',
        'navigation' => 'array',
    ];

    protected static function booted(): void
    {
        // The external "message_id" the OpenAPI contract requires.
        static::creating(function (self $message) {
            $message->uuid ??= (string) Str::uuid();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class);
    }
}
