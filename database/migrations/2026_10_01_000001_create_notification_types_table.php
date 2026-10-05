<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runtime overrides for the notification catalogue in config/notifications.php.
     * Every column except `key` is nullable: null means "use the config value".
     */
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('icon', 100)->nullable();
            $table->json('channels')->nullable();
            $table->string('title_template')->nullable();
            $table->text('body_template')->nullable();
            $table->string('url_template')->nullable();
            $table->boolean('is_active')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_types');
    }
};
