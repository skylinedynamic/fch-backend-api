<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that a client asked us to pull their credit.
 *
 * Under the FCRA a consumer report may only be pulled on the consumer's own
 * written instructions, and iSoftPull can ask for those months after the fact.
 * One row here is that evidence for one request.
 *
 * The wording is copied in rather than referenced: the published documents get
 * new version numbers instead of edits, so a row has to show what that client
 * saw on that day, not what the site says now.
 *
 * Nothing here is ever updated except `pulled_at` / `isoftpull_transaction_id`,
 * which are filled in when the pull that this consent authorised actually
 * happens. `consented_at` in particular is never rewritten - it is the fact
 * being proved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_authorization_consents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');

            // Nullable: an update request re-consents without creating a new
            // credit_profile_requests row, so there is not always one to point at.
            $table->unsignedBigInteger('credit_profile_request_id')->nullable();

            // Exactly as published, e.g. '1.0'. A string, not a decimal - see
            // config/credit_authorization.php.
            $table->string('authorization_version', 32);
            $table->string('authorization_url', 512);

            // What the client was actually shown, verbatim.
            $table->text('credit_authorization_text');
            $table->text('e_consent_text');

            // Two legally distinct consents, so two columns. One boolean for the
            // pair could not show which was given.
            $table->boolean('credit_authorization_accepted')->default(false);
            $table->boolean('e_consent_accepted')->default(false);

            // UTC. When the client ticked the boxes - never overwritten.
            $table->timestamp('consented_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Filled in later, when the authorised pull runs.
            $table->string('isoftpull_transaction_id', 191)->nullable();
            $table->timestamp('pulled_at')->nullable();

            $table->timestamps();

            // The two questions asked of this table: "show me this client's
            // consents" and "show me the consent behind this iSoftPull pull".
            $table->index(['borrower_id', 'consented_at']);
            $table->index('isoftpull_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_authorization_consents');
    }
};
