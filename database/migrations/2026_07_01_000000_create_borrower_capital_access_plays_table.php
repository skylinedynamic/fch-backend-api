<?php

use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('borrower_capital_access_plays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('borrower_id');
            $table->unsignedBigInteger('capital_access_id');
            $table->string('status')->default(BorrowerCapitalAccessPlay::STATUS_RUNNING); // running or completed
            $table->timestamps();

            $table->foreign('borrower_id')
                ->references('id')
                ->on('borrowers')
                ->onDelete('cascade');

            $table->foreign('capital_access_id')
                ->references('id')
                ->on('capital_accesses')
                ->onDelete('cascade');

            $table->unique(['borrower_id', 'capital_access_id'], 'borrower_play_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_capital_access_plays');
    }
};
