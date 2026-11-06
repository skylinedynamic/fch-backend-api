<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * embedded_form_configs.notification_email was saved but never used: the
 * new-submission mail goes to the partner's own email, and no portal sends the
 * field any more. Dropped so the setting cannot look like it does something.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('embedded_form_configs', 'notification_email')) {
            Schema::table('embedded_form_configs', function (Blueprint $table) {
                $table->dropColumn('notification_email');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('embedded_form_configs', 'notification_email')) {
            Schema::table('embedded_form_configs', function (Blueprint $table) {
                $table->string('notification_email', 255)->nullable()->comment('Email to notify on new submission');
            });
        }
    }
};
