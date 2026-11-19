<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A borrower may connect their own Calendly as well - signed in through their
 * partner's Calendly OAuth app (its keys are copied onto the borrower's
 * connection). Their bookings then go into their own Calendly; a borrower who
 * has not connected keeps booking with the partner's.
 *
 * borrower_id is set only on a borrower's own connection (one each); a
 * partner's own has none - still one per partner, kept so by the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendly_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('borrower_id')->nullable()->after('partner_id')->unique();
        });

        // A partner and its borrowers now share partner_id.
        Schema::table('calendly_connections', function (Blueprint $table) {
            $table->dropUnique(['partner_id']);
            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        // Back to one connection per partner: borrowers' own are removed.
        \Illuminate\Support\Facades\DB::table('calendly_connections')->whereNotNull('borrower_id')->delete();

        Schema::table('calendly_connections', function (Blueprint $table) {
            $table->dropIndex(['partner_id']);
            $table->unique('partner_id');
            $table->dropUnique(['borrower_id']);
            $table->dropColumn('borrower_id');
        });
    }
};
