<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status Notes accepts up to 10,000 characters (UpdateFundingApplicationStatusRequest),
 * but the column was a VARCHAR(255), so a longer note - such as who withdrew a
 * file and why (SCRUM-331) - failed on save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->text('status_note')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->string('status_note')->nullable()->change();
        });
    }
};
