<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an appointment came from - booked in FCH, or a Calendly meeting - and,
 * for a Calendly one, the Calendly records it mirrors and the links Calendly
 * gives the invitee. calendly_event_uri is unique: one appointment per
 * Calendly meeting, however many times a webhook or sync sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('source', 20)->default('fch')->after('appointment_code');
            $table->string('calendly_event_uri')->nullable()->unique()->after('microsoft_event_id');
            $table->string('calendly_invitee_uri')->nullable()->after('calendly_event_uri');
            $table->string('calendly_event_type_uri')->nullable()->after('calendly_invitee_uri');
            $table->string('invitee_name')->nullable()->after('assigned_for');
            $table->string('invitee_email')->nullable()->after('invitee_name');
            $table->text('join_url')->nullable()->after('location');
            $table->text('cancel_url')->nullable()->after('join_url');
            $table->text('reschedule_url')->nullable()->after('cancel_url');

            $table->index('source');
            $table->index('calendly_invitee_uri');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['calendly_invitee_uri']);
            $table->dropUnique(['calendly_event_uri']);
            $table->dropColumn([
                'source',
                'calendly_event_uri',
                'calendly_invitee_uri',
                'calendly_event_type_uri',
                'invitee_name',
                'invitee_email',
                'join_url',
                'cancel_url',
                'reschedule_url',
            ]);
        });
    }
};
