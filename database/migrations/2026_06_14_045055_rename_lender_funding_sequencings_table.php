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
        Schema::rename('lender_funding_sequencings', 'lender_visibilities');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('lender_visibilities', 'lender_funding_sequencings');
    }
};
