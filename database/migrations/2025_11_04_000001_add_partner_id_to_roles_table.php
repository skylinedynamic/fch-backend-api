<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'partner_id')) {
                $table->unsignedBigInteger('partner_id')->nullable()->after('id');
            }
        });

        // Drop old unique index and add new one including partner_id
        DB::statement('ALTER TABLE roles DROP INDEX roles_name_guard_name_unique');
        DB::statement('ALTER TABLE roles ADD UNIQUE roles_name_guard_name_partner_unique (name, guard_name, partner_id)');
    }

    public function down(): void
    {
        // Revert unique index
        DB::statement('ALTER TABLE roles DROP INDEX roles_name_guard_name_partner_unique');
        DB::statement('ALTER TABLE roles ADD UNIQUE roles_name_guard_name_unique (name, guard_name)');

        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'partner_id')) {
                $table->dropColumn('partner_id');
            }
        });
    }
};
