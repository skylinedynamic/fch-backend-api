<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attachments are stored as a JSON array of {name, path}; varchar(255)
     * overflowed as soon as a couple of files were attached (strict mode
     * then rejected the insert/update with "Data too long").
     */
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->text('attachments')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->string('attachments')->nullable()->change();
        });
    }
};
