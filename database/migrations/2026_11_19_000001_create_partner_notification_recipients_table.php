<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner profile › Mail Notification: where a partner's notification emails
 * of each type go (PartnerNotificationRecipient::TYPES - leads, clients, more
 * later). One row per partner and type; no row means the partner's own email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('email', 255);
            $table->timestamps();

            $table->unique(['partner_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_notification_recipients');
    }
};
