<?php

namespace App\Models\Assistant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatConversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'assistant_type',
        'partner_id',
        'borrower_id',
        'causer_type',
        'causer_id',
        'status',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The external "session_id" the OpenAPI contract requires — never the
        // internal integer id, so a conversation can't be enumerated from it.
        static::creating(function (self $conversation) {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('id');
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A conversation is visible only to the user who owns it, plus FCH host
     * staff — never cross-tenant, mirroring ActivityLog::isHostUser().
     */
    public function isOwnedBy($user): bool
    {
        if (! $user || ! $this->causer_id || ! $this->causer_type) {
            return false;
        }

        return (int) $this->causer_id === (int) $user->getKey()
            && ltrim($this->causer_type, '\\') === ltrim(get_class($user), '\\');
    }
}
