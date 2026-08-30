<?php

use App\Models\CreditBuilder\CreditAccountApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('credit_account_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->foreignId('credit_account_id')->constrained('credit_accounts')->onDelete('cascade');
            $table->enum('status', CreditAccountApplication::STATUSES)->default(CreditAccountApplication::STATUS_REQUESTED);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_account_applications');
    }
};
