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
        Schema::create('system_metrics', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->decimal('cpu_usage', 5, 2)->comment('Percentage 0-100');
            $blueprint->decimal('ram_usage', 5, 2)->comment('Percentage 0-100');
            $blueprint->decimal('disk_usage', 5, 2)->comment('Percentage 0-100');
            $blueprint->unsignedInteger('users_count')->default(0);
            $blueprint->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_metrics');
    }
};
