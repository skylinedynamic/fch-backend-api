<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where an account is in a guided walkthrough, one row per account per
     * walkthrough. The account is polymorphic (entity_type + entity_id, as on
     * users and subscriptions) so borrowers and partners can each have their
     * own walkthroughs; `walkthrough` says which one the row is for (see
     * WalkthroughProgress::WALKTHROUGHS).
     */
    public function up(): void
    {
        Schema::create('walkthrough_progress', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('walkthrough', 64);
            // Derived from the account's data; refreshed whenever it's checked.
            $table->string('current_step', 32)->nullable();
            $table->json('skipped_steps')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'walkthrough'], 'walkthrough_progress_entity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('walkthrough_progress');
    }
};
