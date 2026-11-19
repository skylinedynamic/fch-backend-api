<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calendly per owner instead of one company account: the host, each partner
 * and each borrower connect their own Calendly, with their own OAuth app keys
 * (entered in the Connect popup, the secrets encrypted by the model).
 *
 * Whose it is: partner_id (the host or partner - for a borrower's, the
 * borrower's partner) and borrower_id (set only on a borrower's own; empty =
 * the partner's, shared by its team). client_id / client_secret /
 * webhook_signing_key are that owner's Calendly OAuth app keys. Before the
 * OAuth sign-in a connection holds only the keys, so the token columns may be
 * empty. Each appointment from Calendly records the connection it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendly_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_id')->nullable()->after('id')->index();
            $table->unsignedBigInteger('borrower_id')->nullable()->after('partner_id')->unique();
            $table->string('client_id')->nullable()->after('borrower_id');
            $table->text('client_secret')->nullable()->after('client_id');
            $table->text('webhook_signing_key')->nullable()->after('client_secret');
        });

        Schema::table('calendly_connections', function (Blueprint $table) {
            $table->string('calendly_user_uri')->nullable()->change();
            $table->text('access_token')->nullable()->change();
            $table->text('refresh_token')->nullable()->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('calendly_connection_id')->nullable()->after('calendly_event_uri')->index();
        });

        // The one connection made so far belongs to whoever connected it. It has
        // no keys of its own yet (they came from .env), so it shows as needing
        // its keys entered and connecting again.
        foreach (DB::table('calendly_connections')->get() as $connection) {
            $user = DB::table('users')->where('id', $connection->connected_by)->first();
            $isBorrower = $user && ($user->entity_type === 'App\\Models\\Borrower\\Borrower' || $user->user_type === 'Borrower');

            DB::table('calendly_connections')->where('id', $connection->id)->update([
                'partner_id' => $user?->partner_id,
                'borrower_id' => $isBorrower ? $user->entity_id : null,
                'status' => 'needs_reconnect',
            ]);

            DB::table('appointments')
                ->whereNotNull('calendly_event_uri')
                ->where('scheduled_by', $connection->connected_by)
                ->update(['calendly_connection_id' => $connection->id]);
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['calendly_connection_id']);
            $table->dropColumn('calendly_connection_id');
        });

        Schema::table('calendly_connections', function (Blueprint $table) {
            $table->dropUnique(['borrower_id']);
            $table->dropIndex(['partner_id']);
            $table->dropColumn(['partner_id', 'borrower_id', 'client_id', 'client_secret', 'webhook_signing_key']);
        });
    }
};
