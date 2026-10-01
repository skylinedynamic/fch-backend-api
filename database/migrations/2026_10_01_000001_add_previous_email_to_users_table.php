<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email a login had before its last confirmed change, kept so the old
 * address can undo that change (from the link it's sent) if it wasn't wanted —
 * e.g. the new address was mistyped and a stranger confirmed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('previous_email')->nullable()->after('pending_email_requested_at');
            $table->timestamp('email_changed_at')->nullable()->after('previous_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['previous_email', 'email_changed_at']);
        });
    }
};
