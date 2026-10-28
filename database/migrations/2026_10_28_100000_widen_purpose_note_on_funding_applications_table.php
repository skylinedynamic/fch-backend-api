<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The funding application's Purpose Note is validated up to 500 characters
 * (portal, partner form and embedded form alike), but the column was a
 * VARCHAR(255), so a note of 256-500 characters failed the save with a server
 * error (R2 BUG-040). TEXT holds the 500 the rule allows, and any limit the
 * business settles on later without another migration.
 *
 * Collateral Available had the same mismatch (validated up to 500, stored in
 * 255) and is widened with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->text('purpose_note')->nullable()->change();
            $table->text('collateral_available')->nullable()->change();
        });
    }

    /**
     * Back to VARCHAR(255). On MySQL a value longer than 255 characters makes
     * this fail (strict mode) rather than cut it short - shorten those first.
     */
    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->string('purpose_note')->nullable()->change();
            $table->string('collateral_available')->nullable()->change();
        });
    }
};
