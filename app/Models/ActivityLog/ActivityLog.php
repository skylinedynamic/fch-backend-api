<?php

namespace App\Models\ActivityLog;

use Spatie\Activitylog\Models\Activity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ActivityLog extends Activity
{
    use HasFactory;

    protected static function booted()
    {
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
                if (!$subject && $activity->subject_type && $activity->subject_id) {
                    $subject = ($activity->subject_type)::find($activity->subject_id);
                }

                if ($subject) {
                    // Infer Partner
                    if (empty($props['partner_id'])) {
                        if ($subject instanceof \App\Models\Partner\Partner)
                            $props['partner_id'] = $subject->id;
                        elseif (isset($subject->partner_id))
                            $props['partner_id'] = (int) $subject->partner_id;
                        elseif ($subject instanceof \App\Models\Appointment\Appointment && $subject->entity_type === \App\Models\Partner\Partner::class) {
                            $props['partner_id'] = (int) $subject->entity_id;
                        }
                    }
                    // Infer Borrower
                    if (empty($props['borrower_id'])) {
                        if ($subject instanceof \App\Models\Borrower\Borrower)
                            $props['borrower_id'] = $subject->id;
                        elseif (isset($subject->borrower_id))
                            $props['borrower_id'] = (int) $subject->borrower_id;
                        elseif ($subject instanceof \App\Models\Invoice\Invoice)
                            $props['borrower_id'] = (int) $subject->borrower_id;
                    }
                }
            }

            if (isset($props['log_type'])) {
                $activity->log_name = $props['log_type'];
            }

            $activity->properties = $props;

            // 3. System User Fallback
            if (!$activity->causer_id && auth()->check()) {
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
    const LOG_TYPE_BORROWER_FREE_TRIAL = 'borrower_free_trial';

    const STATUS_SENT = 'sent';
    const STATUS_PENDING = 'pending';
    const STATUS_FAILED = 'failed';

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

    public function causer(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): \Illuminate\Database\Eloquent\Relations\MorphTo
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
        if (!is_string($search) || trim($search) === '') {
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
