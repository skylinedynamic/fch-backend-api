<?php

use App\Support\Mail\LegacyEmailConfigurationImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per mail provider a partner configures, replacing the key-value
 * `email_configurations`. A row with no partner is the one platform-wide
 * fallback, kept by the host partner.
 *
 * Provider fields live in one encrypted column rather than a column per field:
 * each provider needs different ones, and none of them may sit in the table as
 * plain text. Existing settings are moved across, unverified - the fallback
 * too (see LegacyEmailConfigurationImporter); the old table is left in place
 * so this can be rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_configurations', function (Blueprint $table) {
            $table->id();
            // Null is the platform-wide fallback.
            $table->foreignId('partner_id')->nullable()->constrained('partners')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('from_address')->nullable();
            $table->text('bcc_emails')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('verified_to')->nullable();
            $table->string('verified_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'provider']);
        });

        app(LegacyEmailConfigurationImporter::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_configurations');
    }
};
