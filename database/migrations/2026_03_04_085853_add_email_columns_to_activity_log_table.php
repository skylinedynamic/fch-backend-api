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
            // Drop Spatie/Old Columns if they exist
            $columnsToDrop = [
                'log_name', 'description', 'subject_type', 'subject_id', 
                'causer_type', 'causer_id', 'event', 'batch_uuid'
            ];

            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('activity_log', $column)) {
                    $table->dropColumn($column);
                }
            }

            // Add requested columns if they don't exist
            if (!Schema::hasColumn('activity_log', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn('activity_log', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type')->index();
            }
            if (!Schema::hasColumn('activity_log', 'partner_id')) {
                $table->unsignedBigInteger('partner_id')->nullable()->after('entity_id')->index();
            }
            if (!Schema::hasColumn('activity_log', 'borrower_id')) {
                $table->unsignedBigInteger('borrower_id')->nullable()->after('partner_id')->index();
            }
            
            if (!Schema::hasColumn('activity_log', 'status')) {
                $table->string('status')->default('sent')->after('properties');
            }
            
            if (!Schema::hasColumn('activity_log', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('status');
            }
            if (!Schema::hasColumn('activity_log', 'created_to')) {
                $table->unsignedBigInteger('created_to')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('activity_log', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_to');
            }
            if (!Schema::hasColumn('activity_log', 'deleted_at')) {
                $table->softDeletes()->after('updated_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn([
                'entity_type', 'entity_id', 'partner_id', 'borrower_id', 
                'status', 'created_by','created_to', 'updated_by', 'deleted_at'
            ]);
            
            $table->string('log_name')->nullable();
            $table->text('description')->nullable();
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->string('event')->nullable();
            $table->uuid('batch_uuid')->nullable();
        });
    }
};
