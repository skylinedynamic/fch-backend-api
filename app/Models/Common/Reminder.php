<?php

namespace App\Models\Common;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'entity_id',
        'entity_type',
        'created_by',
        'created_by_entity_type',
        'reference',
        'set_date_time',
        'summary',
        'message',
        'is_reminder',
        'reminder_date_time',
        'attachments',
        'status',
        'send_at',
    ];

    protected $casts = [
        'set_date_time' => 'datetime',
        'reminder_date_time' => 'datetime',
        'send_at' => 'datetime',
        'is_reminder' => 'boolean',
        'attachments' => 'array',
    ];

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'created_by_entity_type', 'created_by');
    }

    public function scopeDue($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('reminder_date_time')
                       ->where('reminder_date_time', '<=', now());
                })->orWhere(function ($q2) {
                    $q2->whereNull('reminder_date_time')
                       ->where(function ($q3) {
                           $q3->where('set_date_time', '<=', now())
                              ->orWhere('send_at', '<=', now());
                       });
                });
            });
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('reminder_date_time')
                       ->where('reminder_date_time', '>=', now());
                })->orWhere(function ($q2) {
                    $q2->whereNull('reminder_date_time')
                       ->where(function ($q3) {
                           $q3->where('set_date_time', '>=', now())
                              ->orWhere('send_at', '>=', now());
                       });
                });
            });
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('summary', 'like', "%{$search}%")
              ->orWhere('message', 'like', "%{$search}%")
              ->orWhere('reference', 'like', "%{$search}%")
              ->orWhere('status', 'like', "%{$search}%");
        });
    }

    public function scopeDateBetween($query, ?string $startDate, ?string $endDate)
    {
        if (!$startDate || !$endDate) {
            return $query;
        }

        $start = $startDate . ' 00:00:00';
        $end = $endDate . ' 23:59:59';

        return $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('set_date_time', [$start, $end])
              ->orWhereBetween('send_at', [$start, $end]);
        });
    }

    public function scopeReferenceType($query, ?string $referenceType)
    {
        if (!$referenceType) {
            return $query;
        }

        return $query->where('reference', $referenceType);
    }

    // The activity date (set_date_time, falling back to send_at, falling back
    // to reminder_date_time) has already passed. Mirrors the frontend's
    // isReminderMissed date-source priority; used both to build the "missed"
    // filter and to exclude missed rows from the plain "pending"/"sent"
    // filters, so a row's badge (Missed vs Sent/Pending) always matches
    // whichever filter surfaced it.
    protected function scopeIsMissedDate($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($q2) {
                $q2->whereNotNull('set_date_time')
                   ->where('set_date_time', '<', now());
            })->orWhere(function ($q2) {
                $q2->whereNull('set_date_time')
                   ->whereNotNull('send_at')
                   ->where('send_at', '<', now());
            })->orWhere(function ($q2) {
                $q2->whereNull('set_date_time')
                   ->whereNull('send_at')
                   ->where('reminder_date_time', '<', now());
            });
        });
    }

    public function scopeStatusFilter($query, ?string $status)
    {
        if (!$status) {
            return $query;
        }

        // "Missed" isn't a stored status — it's a pending/sent reminder whose
        // activity date has already passed (a dismissed/cancelled reminder is
        // just dismissed, never "missed").
        if ($status === 'missed') {
            return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT])
                ->isMissedDate();
        }

        // "dismissable" or "actionable" includes all pending and sent reminders (both upcoming and missed)
        if ($status === 'dismissable' || $status === 'actionable') {
            return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT]);
        }

        // Excluding missed rows from the plain pending/sent filters keeps
        // filter results consistent with the displayed badge — a row shown
        // under "Sent" should never render as "Missed", and vice versa.
        if ($status === self::STATUS_PENDING || $status === self::STATUS_SENT) {
            return $query->where('status', $status)
                ->whereNot(function ($q) {
                    $q->isMissedDate();
                });
        }

        return $query->where('status', $status);
    }
}
