<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resource_providers', function (Blueprint $table) {
            $table->json('desc_list')->nullable()->after('desc');
            $table->string('logo')->nullable()->after('desc_list');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_providers', function (Blueprint $table) {
            $table->dropColumn(['desc_list', 'logo']);
        });
    }
};
