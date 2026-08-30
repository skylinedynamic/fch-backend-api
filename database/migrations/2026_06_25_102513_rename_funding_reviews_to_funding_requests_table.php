<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('funding_reviews', 'funding_requests');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('funding_requests', 'funding_reviews');
    }
};
