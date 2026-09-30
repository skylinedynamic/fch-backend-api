<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Centrally managed, independently versioned system prompts. At most one
        // row per assistant_type is active; activating an older row is a rollback.
        // Rows are never edited in place — a change is a new version — so the
        // (assistant_type, version, content_sha256) recorded on a message always
        // identifies the exact text that produced it.
        Schema::create('ai_assistant_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('assistant_type', 40);
            $table->string('version', 40);
            $table->longText('content');
            $table->char('content_sha256', 64);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['assistant_type', 'version']);
            $table->index(['assistant_type', 'is_active']);
        });

        // Audit trail: which prompt produced each assistant reply, and the
        // structured navigation the backend resolved for it.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('prompt_version', 40)->nullable()->after('usage');
            $table->char('prompt_sha256', 64)->nullable()->after('prompt_version');
            $table->string('prompt_source', 10)->nullable()->after('prompt_sha256'); // database | file
            $table->json('navigation')->nullable()->after('prompt_source');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['prompt_version', 'prompt_sha256', 'prompt_source', 'navigation']);
        });

        Schema::dropIfExists('ai_assistant_prompt_versions');
    }
};
