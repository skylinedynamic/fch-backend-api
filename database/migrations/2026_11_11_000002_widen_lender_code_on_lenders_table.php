<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for the Lender Code's current format, "LND-" . 4 digits . lender id
 * (LND-93245). A database that ran the first version of
 * 2026_11_11_000001 made the column 8 characters wide, for "LND-" + 4 digits.
 * Run LenderCodeSeeder afterwards to give those lenders codes in the format.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->string('lender_code', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left as it is: narrowing would cut codes that need the room.
    }
};
