<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cleans up databases that ran earlier versions of this feature's
     * migrations, which created a prompt-versions table, prompt audit
     * columns and a navigation-routes table. The assistants are now OpenAI
     * Agents configured by id only, so none of it is used. A no-op on a
     * fresh database.
     */
    public function up(): void
    {
        Schema::dropIfExists('ai_assistant_prompt_versions');
        // "Take Me There" destinations: the assistants offer no in-app navigation.
        Schema::dropIfExists('ai_assistant_navigation_routes');

        $stale = array_values(array_filter(
            ['prompt_version', 'prompt_sha256', 'prompt_source'],
            fn (string $column) => Schema::hasColumn('chat_messages', $column)
        ));

        if ($stale) {
            Schema::table('chat_messages', function (Blueprint $table) use ($stale) {
                $table->dropColumn($stale);
            });
        }

        // Old per-assistant vector-store settings (knowledge is on the agent now).
        DB::table('app_configurations')
            ->where('module', 'openai')
            ->whereIn('key', ['wlp_support', 'borrower_support'])
            ->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the dropped table and columns are no longer used.
    }
};
