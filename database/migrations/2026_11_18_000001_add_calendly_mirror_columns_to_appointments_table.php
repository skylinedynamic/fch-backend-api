<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same meeting booked again in the other person's own Calendly, with the
 * creator as its invitee: Calendly lists a meeting only in its host's account,
 * so this is how it shows in both people's Calendly. Unique: one appointment
 * per Calendly meeting, whichever side it is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('calendly_mirror_event_uri')->nullable()->unique()->after('calendly_event_type_uri');
            $table->string('calendly_mirror_invitee_uri')->nullable()->after('calendly_mirror_event_uri');
            $table->unsignedBigInteger('calendly_mirror_connection_id')->nullable()->index()->after('calendly_mirror_invitee_uri');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['calendly_mirror_connection_id']);
            $table->dropUnique(['calendly_mirror_event_uri']);
            $table->dropColumn(['calendly_mirror_event_uri', 'calendly_mirror_invitee_uri', 'calendly_mirror_connection_id']);
        });
    }
};
