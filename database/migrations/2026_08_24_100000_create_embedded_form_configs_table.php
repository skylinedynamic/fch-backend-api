<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedded_form_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id')->unique();
            $table->string('public_key', 64)->unique()->comment('Unique public embed key (fch_pub_...)');
            $table->string('form_title', 255)->default('Personal Loan Application');
            $table->json('allowed_domains')->nullable()->comment('Array of allowed origin domains for CORS validation');
            $table->string('notification_email', 255)->nullable()->comment('Email to notify on new submission');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedded_form_configs');
    }
};
