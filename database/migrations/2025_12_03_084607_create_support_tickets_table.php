<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id');
            $table->string('title');
            $table->string('priority');       // low, medium, high
            $table->string('client_name');
            $table->string('assigned_to')->nullable();
            $table->date('due_date')->nullable();
            $table->string('sla')->nullable();
            $table->softDeletes();            // adds deleted_at
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
