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
        Schema::create('agreement_templates', function (Blueprint $col) {
            $col->id();
            $col->foreignId('partner_id')->nullable()->constrained('partners')->onDelete('cascade');
            $col->string('slug')->index(); // partner-agreement, borrower-agreement
            $col->longText('template_html');
            $col->longText('template_json')->nullable();
            $col->boolean('is_active')->default(true);
            $col->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreement_templates');
    }
};
