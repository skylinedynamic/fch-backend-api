<?php

namespace App\Models\Appointment;

use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;

class Appointment extends Model
{
    use SoftDeletes;

    protected $table = 'appointments';

    protected $fillable = [
        'entity_type',
        'entity_id',
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

    /**
     * Polymorphic relation to the associated entity model.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    /**
     * Accessor: human-readable entity name (partner/borrower/employee).
     */
    public function getEntityNameAttribute(): ?string
    {
        return $this->entity_type ? ucfirst(class_basename($this->entity_type)) : null;
    }

    /**
     * Mutator: resolve short entity name to class name.
     */
    public function setEntityTypeAttribute($value)
    {
        $this->attributes['entity_type'] = match (strtolower($value)) {
            'partner' => Partner::class,
            'borrower' => Borrower::class,
            'employee' => Employee::class,
            default => $value,
        };
    }

    /**
     * Relationship: The user associated with the entity.
     * Use this if you want to get the User derived from the entity (like in Employee).
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'entity_id')
            ->where('entity_type', $this->entity_type);
    }

    public function scheduledBy()
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function assignedFor()
    {
        return $this->belongsTo(User::class, 'assigned_for');
    }

    public function details()
    {
        return $this->hasMany(AppointmentDetail::class, 'appointment_id');
    }

    /** Still ahead, and not already cancelled or completed (R2 BUG-056). */
    public function isCancellable(): bool
    {
        if (in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_COMPLETED], true) || !$this->appointment_date) {
            return false;
        }

        // Stored in UTC.
        return Carbon::parse($this->appointment_date, 'UTC')->isFuture();
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

    public const MEETING_TYPE_INITIAL_CONSULTATION = 'Initial Consultation';
    public const MEETING_TYPE_FOLLOWUP_MEETING = 'Follow-up Meeting';
    public const MEETING_TYPE_BUSINESS_REVIEW = 'Business Review';
    public const MEETING_TYPE_TECHNICAL_SUPPORT = 'Technical Support';
    public const MEETING_TYPE_FEEDBACK_SESSION = 'Feedback Session';
    public const MEETING_TYPE_PARTNERSHIP_MEETING = 'Partnership Meeting';

    public const MEETING_TYPES = [
        self::MEETING_TYPE_INITIAL_CONSULTATION => self::MEETING_TYPE_INITIAL_CONSULTATION,
        self::MEETING_TYPE_FOLLOWUP_MEETING => self::MEETING_TYPE_FOLLOWUP_MEETING,
        self::MEETING_TYPE_BUSINESS_REVIEW => self::MEETING_TYPE_BUSINESS_REVIEW,
        self::MEETING_TYPE_TECHNICAL_SUPPORT => self::MEETING_TYPE_TECHNICAL_SUPPORT,
        self::MEETING_TYPE_FEEDBACK_SESSION => self::MEETING_TYPE_FEEDBACK_SESSION,
        self::MEETING_TYPE_PARTNERSHIP_MEETING => self::MEETING_TYPE_PARTNERSHIP_MEETING,
    ];
}
