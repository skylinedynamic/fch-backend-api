<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Host-managed overrides of config/assistant.php's navigation map: add a
        // route key, repoint one, or switch one off without a code deploy. A row
        // with is_active = false removes a default key from the approved set.
        Schema::create('ai_assistant_navigation_routes', function (Blueprint $table) {
            $table->id();
            $table->string('assistant_type', 40);
            $table->string('route_key', 60);
            $table->string('label', 120);
            $table->string('path', 200);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['assistant_type', 'route_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_navigation_routes');
    }
};
