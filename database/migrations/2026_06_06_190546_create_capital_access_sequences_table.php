<?php

use App\Models\CreditBuilder\CapitalAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('capital_access_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capital_access_id')
                ->constrained('capital_accesses')
                ->onDelete('cascade');
            $table->foreignId('credit_account_id')
                ->constrained('credit_accounts')
                ->onDelete('cascade');
            $table->unsignedInteger('sorting_order')->default(0);
            $table->text('why_this_card')->nullable();
            $table->integer('est_limit_min')->nullable();
            $table->integer('est_limit_max')->nullable();
            $table->string('hard_pull')->default(CapitalAccess::HARD_PULL_YES);
            $table->json('what_you_need')->nullable();
            $table->timestamps();

            $table->unique(['capital_access_id', 'credit_account_id'], 'cas_ca_id_cra_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_access_sequences');
    }
};
