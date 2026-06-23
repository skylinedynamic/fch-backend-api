<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('capital_access_sequences', function (Blueprint $table) {
            $table->string('access_time')->nullable()->after('hard_pull');
            $table->integer('access_day')->nullable()->after('access_time');
            $table->string('access_day_time')->nullable()->after('access_day');
        });
    }

    public function down(): void
    {
        Schema::table('capital_access_sequences', function (Blueprint $table) {
            $table->dropColumn(['access_time', 'access_day', 'access_day_time']);
        });
    }
};
