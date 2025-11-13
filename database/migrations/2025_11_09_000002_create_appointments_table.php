<?php

use App\Models\Appointment\Appointment;
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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_code', 50)->unique();
   
            $table->foreignId('scheduled_by')->constrained('users');
            $table->foreignId('assigned_for')->nullable()->constrained('users');

            $table->string('subject', 255);
            $table->text('description');
            $table->dateTime('appointment_date');
            $table->integer('duration_minutes');
            $table->string('status')->default(Appointment::STATUS_SCHEDULED);
            $table->string('meeting_type')->default(Appointment::MEETING_TYPE_ONLINE);
            $table->string('location', 255);
            $table->text('remarks')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for scheduling queries
            $table->index('meeting_type');
            $table->index('appointment_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
