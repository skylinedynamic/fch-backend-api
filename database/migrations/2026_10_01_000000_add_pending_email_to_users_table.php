<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new login email waits here until it's confirmed from the new address; the
 * account keeps using `email` until then. Lives on users because the login is
 * the thing being changed, whichever record (partner, client, employee) it
 * belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_email_requested_at']);
        });
    }
};
