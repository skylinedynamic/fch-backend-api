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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_type');
            $table->unsignedBigInteger('created_by');
            $table->string('created_by_entity_type');
            $table->text('reference')->nullable();
            $table->dateTime('set_date_time')->nullable();
            $table->string('summary')->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_reminder')->default(true);
            $table->dateTime('reminder_date_time')->nullable();
            $table->string('attachments')->nullable();
            // pending, sent, completed, cancelled
            $table->string('status')->default('pending');
            $table->dateTime('send_at')->nullable();

            $table->timestamps();

            // Polymorphic indexes
            $table->index(['entity_type', 'entity_id']);
            $table->index(['created_by_entity_type', 'created_by']);

            // Useful for scheduled reminder processing
            $table->index(['status', 'set_date_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
