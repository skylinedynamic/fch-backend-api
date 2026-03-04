<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add email, activity and relationship columns to activity_log table.
     * Supports both "New Email" and "New Activity" forms in the UI.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Type: 'email' or 'activity'
            $table->string('type')->default('activity')->after('id');

            // Relationships
            $table->unsignedBigInteger('partner_id')->nullable()->after('type')->index();
            $table->unsignedBigInteger('borrower_id')->nullable()->after('partner_id')->index();
            $table->unsignedBigInteger('lead_id')->nullable()->after('borrower_id')->index();

            // New Activity fields
            $table->string('reference')->nullable()->after('lead_id');         // e.g. Event, Call, Meeting
            $table->string('category')->nullable()->after('reference');        // e.g. Event, Call...
            $table->date('activity_date')->nullable()->after('category');
            $table->unsignedBigInteger('assigned_to')->nullable()->after('activity_date'); // user id
            $table->string('summary')->nullable()->after('assigned_to');
            $table->longText('message_body')->nullable()->after('summary');    // rich text message

            // New Email fields
            $table->text('to_emails')->nullable()->after('message_body');      // JSON array of recipients
            $table->string('subject')->nullable()->after('to_emails');
            $table->json('attachments')->nullable()->after('subject');
            $table->string('status')->default('sent')->after('attachments');   // sent, draft, failed

            // Audit
            $table->unsignedBigInteger('created_by')->nullable()->after('status');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            // Soft deletes
            $table->softDeletes()->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn([
                'type', 'partner_id', 'borrower_id', 'lead_id',
                'reference', 'category', 'activity_date', 'assigned_to',
                'summary', 'message_body',
                'to_emails', 'subject', 'attachments', 'status',
                'created_by', 'updated_by', 'deleted_at',
            ]);
        });
    }
};
