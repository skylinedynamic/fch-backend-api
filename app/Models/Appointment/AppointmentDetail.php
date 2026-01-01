<?php

namespace App\Models\Appointment;


use App\Models\User\User;
use App\Models\FCH\Common\TimeSlot\TimeSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppointmentDetail extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'appointment_id',
        'scheduled_by',
        'assigned_for',
        'time_slot_id',
        'appointment_date',
        'status',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function scheduledBy()
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function assignedFor()
    {
        return $this->belongsTo(User::class, 'assigned_for');
    }

    public function timeSlot()
    {
        return $this->belongsTo(TimeSlot::class, 'time_slot_id');
    }

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUSES = [
        self::STATUS_PENDING => self::STATUS_PENDING,
        self::STATUS_CONFIRMED => self::STATUS_CONFIRMED,
        self::STATUS_REJECTED => self::STATUS_REJECTED,
        self::STATUS_RESCHEDULED => self::STATUS_RESCHEDULED,
    ];
}
