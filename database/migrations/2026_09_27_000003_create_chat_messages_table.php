<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            // The external "message_id" the OpenAPI contract requires. See
            // ChatMessage::booted().
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('role'); // user | assistant | system
            $table->longText('content');
            // Allowlisted action ids detected in an assistant reply (e.g. settings_home).
            // Never raw routes/URLs — see ChatMessage::ALLOWED_ACTIONS.
            $table->json('actions')->nullable();
            // file_search citation titles for an assistant reply — the
            // `sources[]` field of FCH_WLP_Assistant_OpenAPI_v1_1.yaml.
            $table->json('sources')->nullable();
            $table->string('openai_request_id')->nullable();
            $table->json('usage')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
