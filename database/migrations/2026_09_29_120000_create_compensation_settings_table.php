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
        Schema::create('compensation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('partners');

            // Polymorphic owner — Employee today, reusable for other paid
            // entities (e.g. partner-side salesmen) without a new table.
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');

            // None | Hourly | Salary | Per Deal
            $table->string('paid_by')->nullable();

            // Per Deal only: one row per applicable service ("All", or each
            // individually selected service). Null for Hourly/Salary/None,
            // which are a single row with no service breakdown.
            $table->string('service')->nullable();

            // Per Deal only: Commission | Flat Amount — decides how `amount`
            // on this row is read (a percentage vs. a dollar figure).
            $table->string('payout_type')->nullable();

            // The one amount column: hourly rate, annual salary, commission
            // percentage, or flat per-deal amount, per `paid_by`/`payout_type`.
            $table->decimal('amount', 12, 2)->nullable();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('partner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compensation_settings');
    }
};
