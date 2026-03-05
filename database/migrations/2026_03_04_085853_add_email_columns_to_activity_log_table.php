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
            // Reorganizing for "accurate code" (leaning down, removing type, adding subject)
            $table->unsignedBigInteger('partner_id')->nullable()->after('id')->index();
            $table->unsignedBigInteger('borrower_id')->nullable()->after('partner_id')->index();
            $table->text('to_emails')->nullable()->after('borrower_id');
            
            // Add subject line for activities/emails after log_name
            $table->string('subject')->nullable()->after('log_name');
            
            // reference, category, activity_date from activity form
            $table->string('reference')->nullable()->after('properties');
            $table->date('activity_date')->nullable()->after('reference');
            $table->string('category')->nullable()->after('activity_date'); 
            $table->json('attachments')->nullable()->after('category');
            $table->string('status')->default('sent')->after('attachments');
            
            // Audit and Soft Deletes
            $table->unsignedBigInteger('created_by')->nullable()->after('status');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            $table->softDeletes()->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn([
                'partner_id', 'borrower_id', 'to_emails', 'subject',
                'reference', 'activity_date', 'category', 'attachments', 
                'status', 'created_by', 'updated_by', 'deleted_at'
            ]);
        });
    }
};
