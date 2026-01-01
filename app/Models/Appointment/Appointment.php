<?php

namespace App\Models\Appointment;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $table = 'appointments';

    protected $fillable = [
        'appointment_code',
        'microsoft_event_id',
        'scheduled_by',
        'assigned_for',
        'subject',
        'description',
        'appointment_date',
        'timezone',
        'duration_minutes',
        'status',
        'meeting_type',
        'location',
        'remarks',
    ];

    public function scheduledBy()
    {
        return $this->belongsTo(\App\Models\User\User::class, 'scheduled_by');
    }

    public function assignedFor()
    {
        return $this->belongsTo(\App\Models\User\User::class, 'assigned_for');
    }
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RESCHEDULED = 'rescheduled';
    public const STATUS_COMPLETED = 'completed'; // Keeping as it makes sense for lifecycle
    public const STATUS_CANCELLED = 'cancelled'; // Keeping as it makes sense

    public const STATUSES = [
        self::STATUS_PENDING => self::STATUS_PENDING,
        self::STATUS_CONFIRMED => self::STATUS_CONFIRMED,
        self::STATUS_REJECTED => self::STATUS_REJECTED,
        self::STATUS_RESCHEDULED => self::STATUS_RESCHEDULED,
        self::STATUS_COMPLETED => self::STATUS_COMPLETED,
        self::STATUS_CANCELLED => self::STATUS_CANCELLED,
    ];

    public const MEETING_TYPE_ONLINE = 'online';
    public const MEETING_TYPE_IN_PERSON = 'in_person';
    public const MEETING_TYPE_PHONE = 'phone';

    public const MEETING_TYPES = [
        self::MEETING_TYPE_ONLINE => self::MEETING_TYPE_ONLINE,
        self::MEETING_TYPE_IN_PERSON => self::MEETING_TYPE_IN_PERSON,
        self::MEETING_TYPE_PHONE => self::MEETING_TYPE_PHONE,
    ];


}
