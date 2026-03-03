<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pre_qualification_requests', function (Blueprint $table) {
            $table->string('purpose_note')->nullable()->after('purpose_of_funding');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pre_qualification_requests', function (Blueprint $table) {
            $table->dropColumn('purpose_note');
        });
    }
};
