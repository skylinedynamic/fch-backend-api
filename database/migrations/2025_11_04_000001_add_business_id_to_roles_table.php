<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'business_id')) {
                $table->unsignedBigInteger('business_id')->nullable()->after('id');
            }
        });

        // Drop old unique index and add new one including business_id
        DB::statement('ALTER TABLE roles DROP INDEX roles_name_guard_name_unique');
        DB::statement('ALTER TABLE roles ADD UNIQUE roles_name_guard_name_business_unique (name, guard_name, business_id)');
    }

    public function down(): void
    {
        // Revert unique index
        DB::statement('ALTER TABLE roles DROP INDEX roles_name_guard_name_business_unique');
        DB::statement('ALTER TABLE roles ADD UNIQUE roles_name_guard_name_unique (name, guard_name)');

        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'business_id')) {
                $table->dropColumn('business_id');
            }
        });
    }
};
