<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The file name is historical: this once also created a prompt-versions
     * table and prompt audit columns. Prompts now live on the OpenAI platform,
     * so only the navigation column remains (the file is not renamed because
     * environments that already ran it would run it again).
     */
    public function up(): void
    {
        // The structured navigation the backend resolved for an assistant reply.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->json('navigation')->nullable()->after('usage');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('navigation');
        });
    }
};
