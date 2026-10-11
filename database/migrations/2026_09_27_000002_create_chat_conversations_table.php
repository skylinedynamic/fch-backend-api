<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            // The external "session_id" the OpenAPI contract requires —
            // never the internal integer id, so a conversation can't be
            // enumerated from it. See ChatConversation::booted().
            $table->uuid('uuid')->unique();
            $table->string('assistant_type');
            $table->unsignedBigInteger('partner_id')->nullable()->index();
            $table->unsignedBigInteger('borrower_id')->nullable()->index();
            // The authenticated user who owns this thread — never trusted from
            // the request body, always resolved from auth() when a message is sent.
            $table->nullableMorphs('causer');
            $table->string('status')->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assistant_type', 'partner_id']);
            $table->index(['assistant_type', 'borrower_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
