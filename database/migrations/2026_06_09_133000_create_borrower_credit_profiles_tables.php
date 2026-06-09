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
        Schema::create('borrower_credit_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->onDelete('cascade');
            $table->integer('fico_score')->nullable();
            $table->integer('paydex_score')->nullable();
            $table->integer('experian_intelliscore')->nullable();
            $table->integer('equifax_score')->nullable();
            $table->integer('active_tradelines')->nullable();
            $table->decimal('total_credit_limit', 15, 2)->nullable();
            $table->decimal('credit_utilization', 5, 2)->nullable();
            $table->integer('recent_inquiries')->nullable();
            $table->integer('delinquent_accounts')->nullable();
            $table->integer('public_records')->nullable();
            $table->integer('collections')->nullable();
            $table->string('delinquencies_30_60_90')->nullable();
            $table->text('summary')->nullable();
            $table->string('pdf_url')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('borrower_documents')->onDelete('set null');
            $table->json('personal_info')->nullable();
            
            // All accounts analysis
            $table->integer('all_accounts_total')->nullable();
            $table->integer('all_accounts_open')->nullable();
            $table->integer('all_accounts_closed')->nullable();
            $table->string('all_accounts_newest_open_date')->nullable();
            $table->string('all_accounts_oldest_open_date')->nullable();
            $table->json('all_accounts_analysis')->nullable();
            
            // Open accounts analysis
            $table->decimal('open_accounts_monthly_payments', 15, 2)->nullable();
            $table->decimal('open_accounts_balance', 15, 2)->nullable();
            $table->decimal('open_accounts_credit_limit', 15, 2)->nullable();
            $table->decimal('open_accounts_available_credit', 15, 2)->nullable();
            $table->decimal('open_accounts_utilization_ratio', 5, 2)->nullable();
            $table->json('open_accounts_analysis')->nullable();
            
            $table->timestamps();
        });

        Schema::create('borrower_credit_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_credit_profile_id')
                  ->constrained('borrower_credit_profiles')
                  ->onDelete('cascade')
                  ->name('fk_borrower_credit_addresses_profile');
            $table->string('type')->default('CURRENT'); // CURRENT, PREVIOUS
            $table->string('street');
            $table->string('city');
            $table->string('state');
            $table->string('zip');
            $table->string('date_reported')->nullable();
            $table->timestamps();
        });

        Schema::create('borrower_credit_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_credit_profile_id')
                  ->constrained('borrower_credit_profiles')
                  ->onDelete('cascade')
                  ->name('fk_borrower_credit_trades_profile');
            $table->string('company');
            $table->string('date_reported')->nullable();
            $table->string('status')->nullable();
            $table->string('rating')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_type')->nullable();
            $table->string('portfolio_type')->nullable();
            $table->text('remarks')->nullable();
            $table->string('payment_history_start_date')->nullable();
            $table->string('payment_frequency')->nullable();
            $table->string('responsibility')->nullable();
            $table->string('opened_date')->nullable();
            $table->string('terms')->nullable();
            $table->string('last_payment_date')->nullable();
            
            $table->decimal('balance', 15, 2)->nullable();
            $table->decimal('high_credit', 15, 2)->nullable();
            $table->decimal('limit', 15, 2)->nullable();
            $table->decimal('monthly_payment', 15, 2)->nullable();
            $table->decimal('actual_payment', 15, 2)->nullable();
            $table->decimal('past_due', 15, 2)->nullable();
            
            $table->integer('months_reviewed')->nullable();
            $table->string('delinquencies_30_60_90')->nullable();
            $table->string('max_delinquency_date')->nullable();
            
            $table->timestamps();
        });

        Schema::create('borrower_credit_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_credit_profile_id')
                  ->constrained('borrower_credit_profiles')
                  ->onDelete('cascade')
                  ->name('fk_borrower_credit_inquiries_profile');
            $table->string('date')->nullable();
            $table->string('company');
            $table->string('industry')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_credit_inquiries');
        Schema::dropIfExists('borrower_credit_trades');
        Schema::dropIfExists('borrower_credit_addresses');
        Schema::dropIfExists('borrower_credit_profiles');
    }
};
