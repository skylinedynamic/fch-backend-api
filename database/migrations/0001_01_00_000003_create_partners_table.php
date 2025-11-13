<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_host')->default(false); // if admin then true
            $table->string('company_name')->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_phone_number')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('owner_phone_number')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_email')->nullable();
            $table->string('contact_person_phone_number')->nullable();
            $table->string('partner_type')->nullable();
            $table->string('status')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('country')->nullable();
            $table->unsignedBigInteger('assign_to')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for filtering partners
            $table->index('company_name');
            $table->index('owner_name');
            $table->index('contact_person');
            $table->index('partner_type');
            $table->index('is_host');
            $table->index('status');
            $table->index('company_email');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};