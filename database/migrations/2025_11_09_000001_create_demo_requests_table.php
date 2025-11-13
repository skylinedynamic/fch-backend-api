<?php

use App\Models\DemoRequest\DemoRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDemoRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id')->nullable();
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
            
            $table->unsignedBigInteger('assigned_to')->nullable()->constrained('users'); 
            $table->string('assigned_email')->nullable();
            $table->string('assigned_phone')->nullable();
            $table->date('demo_start')->nullable();
            $table->date('demo_end')->nullable();
            $table->string('status')->default(DemoRequest::PENDING); 
            $table->unsignedSmallInteger('duration_days')->nullable(); 
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for common filters
            $table->index('partner_id');
            $table->index('assigned_to');
            $table->index('full_name');
            $table->index('customer_name');
            $table->index('phone');
            $table->index('email');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('demo_requests');
    }
}
