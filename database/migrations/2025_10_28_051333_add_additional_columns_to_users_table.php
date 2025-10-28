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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('email_verified_at');
            $table->date('date_of_birth')->nullable()->after('gender');
            
            $table->string('phone_number_2')->nullable()->after('phone_number')->index();
            
            $table->string('ssn')->nullable()->after('country')->index();
            $table->string('address_line_1')->nullable()->after('ssn');
            $table->string('address_line_2')->nullable()->after('address_line_1');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'date_of_birth',
                'phone_number_2',
                'ssn',
                'address_line_1',
                'address_line_2'
            ]);
        });
    }
};
