<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * activity_log_id lets a reminder open its activity for editing.
     * snooze_history keeps every snooze with the note explaining why.
     */
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_log_id')->nullable()->after('id')->index();
            $table->json('snooze_history')->nullable()->after('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex(['activity_log_id']);
            $table->dropColumn(['activity_log_id', 'snooze_history']);
        });
    }
};
