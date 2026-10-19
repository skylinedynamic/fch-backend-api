<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purpose of Funds takes several purposes, joined in this one string, and the
 * request rules allow 500 characters. At varchar(255) a long list passed
 * validation and then failed on insert. Widening keeps every existing value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->string('purpose_of_funds', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->string('purpose_of_funds', 255)->nullable()->change();
        });
    }
};
