<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's Calendly account, connected over OAuth by a member of the
 * host's team. One row at most. The tokens are stored encrypted (the model's
 * "encrypted" cast), hence text columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendly_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_by')->constrained('users');
            $table->string('calendly_user_uri')->unique();
            $table->string('calendly_organization_uri')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('scheduling_url')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->string('webhook_subscription_uri')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendly_connections');
    }
};
