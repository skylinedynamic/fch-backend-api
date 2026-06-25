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
        Schema::table('lender_visibilities', function (Blueprint $table) {
            $table->string('lender_product_category_id')->nullable()->after('lender_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lender_visibilities', function (Blueprint $table) {
            $table->dropColumn('lender_product_category_id');
        });
    }
};
