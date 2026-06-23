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
        Schema::create('lenders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->json('product_categories')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_title')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('website')->nullable();
            $table->string('submission_method')->nullable();
            $table->string('submission_email')->nullable();
            $table->string('submission_portal_link')->nullable();
            $table->string('iso_status')->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->text('internal_notes')->nullable();
            $table->date('last_verified_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('lenders');
        Schema::enableForeignKeyConstraints();
    }
};
