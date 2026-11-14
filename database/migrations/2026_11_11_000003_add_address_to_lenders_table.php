<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lender's location on Add/Edit Lender: Country, State, City (stored by
 * name, as on employees and contacts) and Address - all optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->string('country')->nullable()->after('website');
            $table->string('state')->nullable()->after('country');
            $table->string('city')->nullable()->after('state');
            $table->string('address', 500)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->dropColumn(['country', 'state', 'city', 'address']);
        });
    }
};
