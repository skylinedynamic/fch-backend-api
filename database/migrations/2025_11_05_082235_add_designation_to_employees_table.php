<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // 🆕 Add designation column after last_name (or wherever fits best)
            if (!Schema::hasColumn('employees', 'designation')) {
                $table->string('department')->nullable()->after('last_name');
                $table->string('designation')->nullable()->after('department');
                $table->string('job_status')->nullable()->after('designation');
                $table->string('joining_date')->nullable()->after('job_status');
                $table->string('identification_number')->nullable()->after('joining_date');
                $table->string('blood_group')->nullable()->after('identification_number');
                $table->string('place_of_birth')->nullable()->after('blood_group');
                $table->string('emergency_contact')->nullable()->after('place_of_birth');
                $table->string('emergency_phone')->nullable()->after('emergency_contact');
                $table->string('marital_status')->nullable()->after('emergency_phone');
                $table->string('no_of_children')->nullable()->after('marital_status');
                $table->string('address')->nullable()->after('no_of_children');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'department', 'designation', 'job_status', 'joining_date',
                'identification_number', 'blood_group', 'place_of_birth',
                'emergency_contact', 'emergency_phone', 'marital_status', 'no_of_children','address')) {
                $table->dropColumn('department');
                $table->dropColumn('designation');
                $table->dropColumn('job_status');
                $table->dropColumn('joining_date');
                $table->dropColumn('identification_number');
                $table->dropColumn('blood_group');
                $table->dropColumn('place_of_birth');
                $table->dropColumn('emergency_contact');
                $table->dropColumn('emergency_phone');
                $table->dropColumn('marital_status');
                $table->dropColumn('no_of_children');
                $table->dropColumn('address');
            }
        });
    }
};
