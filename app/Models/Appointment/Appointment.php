<?php

namespace App\Models\Appointment;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $table = 'appointments';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUSES = [
        self::STATUS_SCHEDULED => self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS => self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED => self::STATUS_COMPLETED,
        self::STATUS_CANCELLED => self::STATUS_CANCELLED,
        self::STATUS_RESCHEDULED => self::STATUS_RESCHEDULED,
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
