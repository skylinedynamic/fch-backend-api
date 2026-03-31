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
        Schema::create('agreement_signs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('agreement_template_id');
            $table->string('ip_address')->nullable();
            $table->longText('content_html'); // Snapshot of the agreement
            $table->timestamps();

            $table->foreign('agreement_template_id')->references('id')->on('agreement_templates')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreement_signs');
    }
};
