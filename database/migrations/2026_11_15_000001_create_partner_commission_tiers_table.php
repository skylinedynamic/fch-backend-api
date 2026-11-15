<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner profile › Commissions: a partner's Funding commission is paid by
 * funded-deal count tiers (0-10, 11-20, 21 and up), each a percentage of the
 * success fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_commission_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            // Funded deals, inclusive. A null max is the open-ended last tier ("21 and up").
            $table->unsignedInteger('min_count');
            $table->unsignedInteger('max_count')->nullable();
            // The partner's share (%) of the success fee for deals in this tier.
            $table->decimal('percentage', 5, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_commission_tiers');
    }
};
