<?php

namespace App\Models\ActivityLog;

use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Activity;

class ActivityLog extends Activity
{
    use HasFactory;
    use SoftDeletes;

    protected static function booted()
    {
        static::updated(function ($activity) {
            // Cancel pending reminders if the activity date changed or logic dictates.
            // For now, if the activity is updated, we might need to cascade updates.
            // Since there is no formal edit UI yet, we add this for future-proofing.
            // A more complex implementation could diff the properties['activity_date'].
        });

        static::creating(function ($activity) {
            $props = is_array($activity->properties) ? $activity->properties : [];

            // 1. Move virtual fields into properties bag with type safety
            foreach (['partner_id', 'borrower_id', 'status', 'log_type'] as $field) {
                if (isset($activity->{$field})) {
                    $val = $activity->{$field};
                    if (in_array($field, ['partner_id', 'borrower_id'])) {
                        $val = $val ? (int) $val : null;
                    }
                    $props[$field] = $val;
                    unset($activity->{$field});
                }
            }

            // 2. Infer IDs if missing
            if (empty($props['partner_id']) || empty($props['borrower_id'])) {
                $subject = $activity->subject ?? null;
                if (! $subject && $activity->subject_type && $activity->subject_id) {
                    $subject = ($activity->subject_type)::find($activity->subject_id);
                }

                if ($subject) {
                    // Infer Partner
                    if (empty($props['partner_id'])) {
                        if ($subject instanceof Partner) {
                            $props['partner_id'] = $subject->id;
                        } elseif (isset($subject->partner_id)) {
                            $props['partner_id'] = (int) $subject->partner_id;
                        } elseif ($subject instanceof Appointment && $subject->entity_type === Partner::class) {
                            $props['partner_id'] = (int) $subject->entity_id;
                        }
                    }
                    // Infer Borrower
                    if (empty($props['borrower_id'])) {
                        if ($subject instanceof Borrower) {
                            $props['borrower_id'] = $subject->id;
                        } elseif (isset($subject->borrower_id)) {
                            $props['borrower_id'] = (int) $subject->borrower_id;
                        } elseif ($subject instanceof Invoice) {
                            $props['borrower_id'] = (int) $subject->borrower_id;
                        }
                    }
                }
            }

            if (isset($props['log_type'])) {
                $activity->log_name = $props['log_type'];
            }

            $activity->properties = $props;

            // 3. System User Fallback
            if (! $activity->causer_id && auth()->check()) {
                $activity->causer_id = auth()->id();
                $activity->causer_type = get_class(auth()->user());
            }
        });
    }

    protected $table = 'activity_log';

    const TYPE_EMAIL = 'email';

    const TYPE_ACTIVITY = 'activity';

    // New Log Types
    const LOG_TYPE_EMAIL_UPDATE = 'email_update';

    const LOG_TYPE_PARTNER_CREATE = 'partner_create';

    const LOG_TYPE_PARTNER_UPDATE = 'partner_update';

    const LOG_TYPE_PARTNER_CONTACT_UPDATE = 'partner_contact_update';

    /** The host changed the partner's Email, SMS, Branding or Payment settings (Partner profile › Configuration). */
    const LOG_TYPE_PARTNER_CONFIGURATION_UPDATE = 'partner_configuration_update';

    const LOG_TYPE_PARTNER_DELETE = 'partner_delete';

    const LOG_TYPE_EMAIL_SENT = 'email_sent';

    const LOG_TYPE_ACTIVITY = 'activity';

    const LOG_TYPE_BORROWER_CREATE = 'borrower_create';

    const LOG_TYPE_BORROWER_UPDATE = 'borrower_update';

    const LOG_TYPE_BORROWER_CONTACT_UPDATE = 'borrower_contact_update';

    const LOG_TYPE_LEAD_CREATE = 'lead_create';

    const LOG_TYPE_LEAD_UPDATE = 'lead_update';

    const LOG_TYPE_LEAD_DETAILS_UPDATE = 'lead_details_update';

    const LOG_TYPE_LEAD_CONTACT_UPDATE = 'lead_contact_update';

    const LOG_TYPE_LEAD_DELETE = 'lead_delete';

    const LOG_TYPE_BATCH_PROCESS = 'batch_process';

    const LOG_TYPE_INVOICE_CREATE = 'invoice_create';

    const LOG_TYPE_USER_CREATE = 'user_create';

    const LOG_TYPE_USER_UPDATE = 'user_update';

    const LOG_TYPE_APPOINTMENT_CREATE = 'appointment_create';

    const LOG_TYPE_APPOINTMENT_UPDATE = 'appointment_update';

    const LOG_TYPE_APPOINTMENT_DELETE = 'appointment_delete';

    const LOG_TYPE_ROLE_CREATE = 'role_create';

    const LOG_TYPE_ROLE_UPDATE = 'role_update';

    const LOG_TYPE_PERMISSION_UPDATE = 'permission_update';

    const LOG_TYPE_EMPLOYEE_CREATE = 'employee_create';

    const LOG_TYPE_EMPLOYEE_UPDATE = 'employee_update';

    const LOG_TYPE_EMPLOYEE_DELETE = 'employee_delete';

    const LOG_TYPE_PARTNER_PAYMENT = 'partner_payment';

    const LOG_TYPE_BORROWER_PAYMENT = 'borrower_payment';

    const LOG_TYPE_BUSINESS_UPDATE = 'business_update';

    const LOG_TYPE_DOCUMENT_UPLOAD = 'document_upload';

    const LOG_TYPE_CHECKLIST_UPDATE = 'checklist_update';

    const LOG_TYPE_TASK_VERIFICATION = 'task_verification';

    // A client's credit profile saved or removed on Client > Credit Profile (BUG-075).
    const LOG_TYPE_CREDIT_PROFILE_SAVE = 'credit_profile_save';

    const LOG_TYPE_CREDIT_PROFILE_DELETE = 'credit_profile_delete';

    const LOG_TYPE_FUNDING_REQUEST_CREATE = 'funding_request_create';

    const LOG_TYPE_FUNDING_REQUEST_STATUS_UPDATE = 'funding_request_status_update';
    const LOG_TYPE_FUNDING_APPLICATION_STATUS_UPDATE = 'funding_application_status_update';

    const LOG_TYPE_BORROWER_FREE_TRIAL = 'borrower_free_trial';

    const LOG_TYPE_PARTNER_FREE_TRIAL = 'partner_free_trial';

    const STATUS_SENT = 'sent';

    const STATUS_PENDING = 'pending';

    const STATUS_FAILED = 'failed';

    const VISIBILITY_PRIVATE = 'private';

    const VISIBILITY_INTERNAL = 'internal';

    const VISIBILITY_PARTNER = 'partner';

    const VISIBILITY_EVERYONE = 'everyone';

    const VISIBILITY_LEVELS = [
        self::VISIBILITY_PRIVATE,
        self::VISIBILITY_INTERNAL,
        self::VISIBILITY_PARTNER,
        self::VISIBILITY_EVERYONE,
    ];

    const VISIBILITY_LABELS = [
        self::VISIBILITY_PRIVATE => 'Private',
        self::VISIBILITY_INTERNAL => 'Internal',
        self::VISIBILITY_PARTNER => 'Partner',
        self::VISIBILITY_EVERYONE => 'Everyone',
    ];

    /** The activity Reference that can be assigned to a staff member ("Want to Assign"). */
    const TASK_REFERENCE = 'Task';

    const TASK_PRIORITY_LOW = 'low';

    const TASK_PRIORITY_MEDIUM = 'medium';

    const TASK_PRIORITY_HIGH = 'high';

    const TASK_PRIORITY_LABELS = [
        self::TASK_PRIORITY_LOW => 'Low',
        self::TASK_PRIORITY_MEDIUM => 'Medium',
        self::TASK_PRIORITY_HIGH => 'High',
    ];

    const TASK_STATUS_OPEN = 'open';

    const TASK_STATUS_IN_PROGRESS = 'in_progress';

    const TASK_STATUS_COMPLETE = 'complete';

    const TASK_STATUS_LABELS = [
        self::TASK_STATUS_OPEN => 'Open',
        self::TASK_STATUS_IN_PROGRESS => 'In Progress',
        self::TASK_STATUS_COMPLETE => 'Complete',
    ];

    /**
     * Who gets the reminder an assigned Task sets ("Who gets the reminder"):
     * its author, its assignee, or both. Mirrored in the admin panel's
     * config/activityTask.ts - change both together.
     */
    const REMINDER_RECIPIENT_CREATOR = 'creator';

    const REMINDER_RECIPIENT_ASSIGNEE = 'assignee';

    const REMINDER_RECIPIENT_BOTH = 'both';

    const REMINDER_RECIPIENTS = [
        self::REMINDER_RECIPIENT_CREATOR,
        self::REMINDER_RECIPIENT_ASSIGNEE,
        self::REMINDER_RECIPIENT_BOTH,
    ];

    /**
     * Who the reminder an activity sets goes to: the choice made on a Task
     * assigned to someone else (both, when none was made); otherwise there is
     * only its author.
     */
    public static function reminderRecipientFor(array $props, ?int $causerId): string
    {
        $assigneeId = (int) ($props['task']['assigned_to'] ?? 0);
        if (!$assigneeId || $assigneeId === (int) $causerId) {
            return self::REMINDER_RECIPIENT_CREATOR;
        }

        return in_array($props['reminder_recipient'] ?? null, self::REMINDER_RECIPIENTS, true)
            ? $props['reminder_recipient']
            : self::REMINDER_RECIPIENT_BOTH;
    }

    /**
     * Fields whose value is never written to an activity entry - plain or
     * encrypted. Entries are shown to staff (and some to the client), so a
     * changed SSN is recorded as changed, not repeated.
     */
    const SENSITIVE_FIELD_MARKERS = ['ssn', 'ein', 'tax_id', 'date_of_birth', 'account_number', 'routing'];

    public static function isSensitiveField(string $field): bool
    {
        foreach (self::SENSITIVE_FIELD_MARKERS as $marker) {
            if ($field === $marker || str_contains($field, $marker . '_') || str_ends_with($field, '_' . $marker)) {
                return true;
            }
        }

        return false;
    }

    /** "ssn_encrypted" -> "SSN", "business_ein" -> "Business EIN", "first_name" -> "First Name". */
    public static function fieldLabel(string $field): string
    {
        $label = ucwords(str_replace('_', ' ', preg_replace('/_encrypted$/', '', $field)));

        return preg_replace_callback('/\b(Ssn|Ein|Duns)\b/', fn (array $m) => strtoupper($m[1]), $label);
    }

    /**
     * When an assigned Task without a reminder of its own reminds its
     * assignee: this time of day on the due date, in the assigner's timezone
     * (or at the due time itself, when that is earlier).
     */
    const TASK_DUE_REMINDER_TIME = '09:00:00';

    /**
     * "Reminder Time" as the activity forms store it: reminder_preset, and for
     * "custom_time_before" reminder_time_value + reminder_time_unit. Mirrors
     * REMINDER_PRESETS / REMINDER_TIME_UNITS in the admin frontend's appConfig.
     */
    const REMINDER_PRESET_DEFAULT = '30_minutes';

    const REMINDER_PRESET_CUSTOM_TIME_BEFORE = 'custom_time_before';

    const REMINDER_PRESET_CUSTOM_DATE_TIME = 'custom_date_time';

    /** How far ahead each fixed preset reminds, in minutes. */
    const REMINDER_PRESET_MINUTES = [
        '5_minutes' => 5, '10_minutes' => 10, '15_minutes' => 15, '30_minutes' => 30,
        '1_hour' => 60, '1_day' => 1440, '2_days' => 2880, '3_days' => 4320,
        '1_week' => 10080, '2_weeks' => 20160, '3_weeks' => 30240, '1_month' => 43200,
    ];

    const REMINDER_TIME_VALUE_DEFAULT = '30';

    const REMINDER_TIME_UNIT_MINUTES = 'Minutes';

    const REMINDER_TIME_UNIT_HOURS = 'Hours';

    const REMINDER_TIME_UNIT_DAYS = 'Days';

    const REMINDER_TIME_UNIT_WEEKS = 'Weeks';

    const REMINDER_TIME_UNIT_MONTHS = 'Months';

    /** Minutes in one of each custom "time before" unit. */
    const REMINDER_TIME_UNIT_MINUTES_EACH = [
        self::REMINDER_TIME_UNIT_MINUTES => 1,
        self::REMINDER_TIME_UNIT_HOURS => 60,
        self::REMINDER_TIME_UNIT_DAYS => 1440,
        self::REMINDER_TIME_UNIT_WEEKS => 10080,
        self::REMINDER_TIME_UNIT_MONTHS => 43200,
    ];

    /**
     * A task's stored due date ("Y-m-d H:i:s", UTC) as a wall-clock value in
     * $timezone (the viewer's when null). Tasks saved before the due date had
     * a time hold a bare local day, read as that day's midnight.
     */
    public static function taskDueDateLocal(?string $stored, ?string $timezone = null): ?string
    {
        if (empty($stored)) {
            return null;
        }

        return strlen($stored) === 10
            ? "{$stored} 00:00:00"
            : toTimezone($stored, $timezone, 'Y-m-d H:i:s');
    }

    protected $fillable = [
        'log_name',
        'description',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'event',
        'batch_uuid',
        'visibility',
    ];

    protected $attributes = [
        'visibility' => self::VISIBILITY_INTERNAL,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getPartnerIdAttribute()
    {
        return $this->properties['partner_id'] ?? null;
    }

    public function setPartnerIdAttribute($value)
    {
        $props = $this->properties ?? [];
        $props['partner_id'] = $value;
        $this->properties = $props;
    }

    public function getBorrowerIdAttribute()
    {
        return $this->properties['borrower_id'] ?? null;
    }

    public function setBorrowerIdAttribute($value)
    {
        $props = $this->properties ?? [];
        $props['borrower_id'] = $value;
        $this->properties = $props;
    }

    public function getStatusAttribute()
    {
        return $this->properties['status'] ?? null;
    }

    public function setStatusAttribute($value)
    {
        $props = $this->properties ?? [];
        $props['status'] = $value;
        $this->properties = $props;
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Only manual "activity" entries can be edited or deleted at all — the
     * automatic audit trail is never touched.
     */
    public function isManualActivity(): bool
    {
        $logType = strtolower($this->log_name ?? '');

        if ($logType !== 'activity' && $logType !== self::TYPE_ACTIVITY) {
            return false;
        }

        // The type alone is not enough: the system writes its own events under
        // the "activity" type too (an application submitted, an edit that cost a
        // signature, a document generated), with the acting user as causer.
        // Those are audit records - nobody edits or deletes them, least of all
        // the person they are about.
        return ! $this->isSystemEvent();
    }

    /**
     * Written by the application itself rather than typed in by a person.
     *
     * Every system event goes through logSystemEvent(), which stamps an
     * `event_label`; an activity a person creates never carries one.
     */
    public function isSystemEvent(): bool
    {
        $properties = $this->properties;
        if ($properties instanceof \Illuminate\Support\Collection) {
            $properties = $properties->toArray();
        }

        return is_array($properties) && array_key_exists('event_label', $properties);
    }

    /**
     * Whether $user is the person who created this entry. Activities are
     * written with the authenticated user as the causer, so only that user may
     * edit or delete it — everyone else sees it read-only.
     */
    public function isOwnedBy($user): bool
    {
        if (! $user || ! $this->causer_id || ! $this->causer_type) {
            return false;
        }

        return (int) $this->causer_id === (int) $user->getKey()
            && ltrim($this->causer_type, '\\') === ltrim(get_class($user), '\\');
    }

    /**
     * Edit and delete follow the same rule: your own manual activity only.
     */
    public function isEditableBy($user): bool
    {
        return $this->isManualActivity() && $this->isOwnedBy($user);
    }

    /** Whether this is a Task assigned to a staff member ("Want to Assign"). */
    public function isAssignedTask(): bool
    {
        $task = collect($this->properties)->get('task');

        return is_array($task) && !empty($task['assigned_to']);
    }

    /**
     * Who may reschedule a reminder of this activity: anyone with the reminder,
     * except on an assigned Task - its date is the creator's to move (the
     * assignee may only snooze or dismiss their own reminder).
     */
    public function canRescheduleBy($user): bool
    {
        return !$this->isAssignedTask() || $this->isOwnedBy($user);
    }

    /**
     * Who may move an assigned Task between Open / In Progress / Complete from
     * its reminder: the person who created it and the one it is assigned to.
     * Editing the rest of the activity stays with its creator (isEditableBy).
     */
    public function canUpdateTaskStatusBy($user): bool
    {
        if (!$user || !$this->isAssignedTask()) {
            return false;
        }

        return $this->isOwnedBy($user)
            || (int) collect($this->properties)->get('task')['assigned_to'] === (int) $user->getKey();
    }

    /**
     * FCH host staff (Admin/Employee, or a Partner user flagged is_host) — the
     * same "sees everything" tier used throughout the app's role checks.
     *
     * user_type is the primary discriminator, checked first and on its own
     * for Admin/Employee. A Partner user additionally needs their partner
     * record's is_host flag. Every user — including Borrowers — carries a
     * partner_id recording who originated them, so `isset($user->partner)`
     * alone is true for borrowers too and must never be used as a stand-in
     * for "this is a partner-side user".
     */
    public static function isHostUser($user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->user_type === User::ADMIN || $user->user_type === User::EMPLOYEE) {
            return true;
        }

        return $user->user_type === User::PARTNER
            && isset($user->partner)
            && (int) ($user->partner->is_host ?? 0) === 1;
    }

    /**
     * Only FCH host staff may reclassify an activity's visibility — including
     * on rows they didn't create and system-generated audit entries, which
     * isEditableBy() deliberately never opens up to anyone.
     */
    public function canChangeVisibilityBy($user): bool
    {
        return self::isHostUser($user);
    }

    /**
     * Single source of truth for the 4-level visibility model, shared by
     * every list() query and by canChangeVisibilityBy()'s sibling checks:
     *  - private:  the author only
     *  - internal: FCH host staff + author
     *  - partner:  FCH host staff + the owning partner's own users + author
     *  - everyone: FCH host staff + partner + borrower + author
     * The author can always see their own row regardless of level, including
     * their own Private entries; nobody else ever sees another author's
     * Private entry, host staff included.
     */
    public function scopeVisibleTo($query, $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $userType = get_class($user);
        $userId = $user->getKey();

        return $query->where(function ($outer) use ($user, $userType, $userId) {
            $outer->where(function ($own) use ($userType, $userId) {
                $own->where('causer_id', $userId)
                    ->where('causer_type', $userType);
            });

            if (self::isHostUser($user)) {
                $outer->orWhereIn('visibility', [
                    self::VISIBILITY_INTERNAL,
                    self::VISIBILITY_PARTNER,
                    self::VISIBILITY_EVERYONE,
                ]);
            } elseif ($user->user_type === User::PARTNER && isset($user->partner)) {
                $outer->orWhere(function ($partnerScope) use ($user) {
                    $partnerScope->whereIn('visibility', [self::VISIBILITY_PARTNER, self::VISIBILITY_EVERYONE])
                        ->where(function ($pId) use ($user) {
                            $pId->where('properties->partner_id', $user->partner_id)
                                ->orWhere('properties->partner_id', (string) $user->partner_id);
                        });
                });
            } elseif ($user->user_type === User::BORROWER) {
                // Only the shared entries about this borrower - not every
                // borrower's on the platform.
                $borrowerId = $user->entity_id;
                $outer->orWhere(function ($shared) use ($borrowerId) {
                    $shared->where('visibility', self::VISIBILITY_EVERYONE)
                        ->where(function ($about) use ($borrowerId) {
                            $about->where('properties->borrower_id', $borrowerId)
                                ->orWhere('properties->borrower_id', (string) $borrowerId)
                                ->orWhere(function ($subject) use ($borrowerId) {
                                    $subject->where('subject_type', \App\Models\Borrower\Borrower::class)
                                        ->where('subject_id', $borrowerId);
                                });
                        });
                });
            }
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function getLogTypeAttribute()
    {
        return $this->log_name;
    }

    public function setLogTypeAttribute($value)
    {
        $this->attributes['log_name'] = $value;
    }

    public function scopeSearch($query, ?string $search)
    {
        if (! is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('properties->subject', 'like', "%{$search}%")
                ->orWhere('properties->summary', 'like', "%{$search}%")
                ->orWhere('properties->reference', 'like', "%{$search}%")
                ->orWhere('properties->to_emails', 'like', "%{$search}%")
                ->orWhere('properties->description', 'like', "%{$search}%");
        });
    }
}
