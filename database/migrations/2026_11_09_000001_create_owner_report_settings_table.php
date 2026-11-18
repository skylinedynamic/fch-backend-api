<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who gets the host owner's weekly report. Off until the owner sets it up: no
 * row means no report. last_sent_period_from is the Sunday of the week last
 * reported, kept only as a record: it does not stop a report going out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_report_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->unique()->constrained('partners')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->json('recipients');
            $table->date('last_sent_period_from')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_report_settings');
    }
};
