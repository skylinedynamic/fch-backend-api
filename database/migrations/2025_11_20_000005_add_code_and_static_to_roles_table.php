<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'code')) {
                $table->string('code')->nullable()->after('name');
            }
            if (!Schema::hasColumn('roles', 'is_static')) {
                $table->boolean('is_static')->default(false)->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'is_static')) {
                $table->dropColumn('is_static');
            }
            if (Schema::hasColumn('roles', 'code')) {
                $table->dropColumn('code');
            }
        });
    }
};