<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A borrower's "Request update" opens a credit profile request even when they
 * never submitted the request form (the host saved their report directly). It
 * is built from their profile, which may not hold every detail, so the details
 * may be empty. The request form itself still requires them all
 * (StoreCreditProfileRequestRequest). Existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_profile_requests', function (Blueprint $table) {
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
            $table->string('address')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('state')->nullable()->change();
            $table->string('zip')->nullable()->change();
            $table->text('ssn')->nullable()->change(); // encrypted, hence text
            $table->date('date_of_birth')->nullable()->change();
            $table->string('phone')->nullable()->change();
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Back to required. A row made by an update request may hold an empty
     * detail; it is given an empty string (or a placeholder date) first, so the
     * columns can be made NOT NULL again.
     */
    public function down(): void
    {
        foreach (['first_name', 'last_name', 'address', 'city', 'state', 'zip', 'ssn', 'phone', 'email'] as $column) {
            DB::table('credit_profile_requests')->whereNull($column)->update([$column => '']);
        }
        DB::table('credit_profile_requests')->whereNull('date_of_birth')->update(['date_of_birth' => '1900-01-01']);

        Schema::table('credit_profile_requests', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->string('address')->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
            $table->string('state')->nullable(false)->change();
            $table->string('zip')->nullable(false)->change();
            $table->text('ssn')->nullable(false)->change();
            $table->date('date_of_birth')->nullable(false)->change();
            $table->string('phone')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
