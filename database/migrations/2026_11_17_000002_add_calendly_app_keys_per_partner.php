<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendly app keys per partner: each partner (the host, a WLP, a certified
 * partner) keeps its own Calendly OAuth app's Client ID and Client Secret
 * (encrypted by the model), entered in Settings > Calendly - by the account
 * itself, or by the host for it. Its webhook signing key is already here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendly_connections', function (Blueprint $table) {
            $table->string('client_id')->nullable()->after('partner_id');
            $table->text('client_secret')->nullable()->after('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('calendly_connections', function (Blueprint $table) {
            $table->dropColumn(['client_id', 'client_secret']);
        });
    }
};
