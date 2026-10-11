<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One SMS account per partner (host or white-label), set in Settings > SMS
 * Configuration.
 *
 * Provider credentials live in one encrypted column rather than a column per
 * field: each provider needs different ones, and none of them may sit in the
 * table as plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->unique()->constrained('partners')->cascadeOnDelete();
            // A country code from config/locations.php (USA, CAN).
            $table->string('country', 3);
            $table->string('provider', 32);
            $table->boolean('is_enabled')->default(false);
            $table->string('from_number', 20)->nullable();
            $table->text('credentials')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_configurations');
    }
};
