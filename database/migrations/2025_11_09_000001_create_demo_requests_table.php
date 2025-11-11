<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDemoRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('full_name')->nullable();
            $table->string('customer_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('joining_date')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('website')->nullable();
            
            $table->unsignedBigInteger('assigned_to')->nullable(); // user id of agent
            $table->string('assigned_email')->nullable(); // user id of agent
            $table->string('assigned_phone')->nullable(); // user id of agent
            $table->date('demo_start')->nullable();
            $table->date('demo_end')->nullable();
            $table->string('status')->default('pending'); // pending, active, expired, declined, etc.
            $table->unsignedSmallInteger('duration_days')->nullable(); // e.g. 7 or 10
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['business_id']);
            $table->index(['status']);
            $table->index(['assigned_to']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('demo_requests');
    }
}
