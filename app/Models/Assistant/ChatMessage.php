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

    /**
     * The only navigation action recognized anywhere in this feature, shaped
     * exactly like FCH_WLP_Assistant_OpenAPI_v1_1.yaml's `actions[]` schema
     * (type/id/label/path all `const`). An assistant reply can never
     * introduce a new one: this fixed, server-side constant is the sole
     * source of the id -> action mapping, never model or user input.
     */
    public const ALLOWED_ACTIONS = [
        'settings_home' => [
            // Matches the system prompt's literal CTA text, which the model
            // renders in bold markdown ("**Take Me There**") — see
            // SupportAssistantService::processReply().
            'marker' => 'Take Me There',
            'type' => 'navigate',
            'label' => 'Take Me There',
            'path' => '/admin/settings',
        ],
    ];

    protected $fillable = [
        'uuid',
        'conversation_id',
        'role',
        'content',
        'actions',
        'sources',
        'openai_request_id',
        'usage',
    ];

    protected $casts = [
        'actions' => 'array',
        'sources' => 'array',
        'usage' => 'array',
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
