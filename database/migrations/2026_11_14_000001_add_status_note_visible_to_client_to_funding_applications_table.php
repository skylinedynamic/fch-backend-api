<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a funding application's status note is shown to the client
 * (R3-BUG-016). Off by default: a note is internal unless the team ticks
 * "Visible to client".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->boolean('status_note_visible_to_client')->default(false)->after('status_note');
        });
    }

    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->dropColumn('status_note_visible_to_client');
        });
    }
};
