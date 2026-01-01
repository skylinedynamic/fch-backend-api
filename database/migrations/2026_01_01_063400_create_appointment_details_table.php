<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\Appointment\AppointmentDetail;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->onDelete('cascade');
            $table->foreignId('scheduled_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_for')->constrained('users')->onDelete('cascade');
            $table->foreignId('time_slot_id')->constrained('time_slots')->onDelete('cascade');
            $table->date('appointment_date');
            $table->enum('status', [AppointmentDetail::STATUS_PENDING, AppointmentDetail::STATUS_CONFIRMED, AppointmentDetail::STATUS_REJECTED, AppointmentDetail::STATUS_RESCHEDULED])->default(AppointmentDetail::STATUS_PENDING);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_details');
    }
};
