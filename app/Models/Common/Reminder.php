<?php

namespace App\Models\Common;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Employee\Employee;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

class Reminder extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'activity_log_id',
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
        'snooze_history',
        'status',
        'send_at',
    ];

    protected $casts = [
        'set_date_time' => 'datetime',
        'reminder_date_time' => 'datetime',
        'send_at' => 'datetime',
        'is_reminder' => 'boolean',
        'attachments' => 'array',
        'snooze_history' => 'array',
    ];

    /**
     * A reminder belongs to the user who created it. created_by used to hold
     * the creator's *entity* id, and every host admin shares the one host
     * partner entity, so filtering on it showed each of them the whole
     * organisation's reminders. The column pair is polymorphic, so new rows
     * point it at the User instead. Rows written the old way carry no user
     * attribution and stay visible entity-wide rather than vanishing.
     */
    public function scopeCreatedByUser($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where(function ($own) use ($user) {
                $own->where('created_by_entity_type', User::class)
                    ->where('created_by', $user->id);
            })->orWhere(function ($legacy) use ($user) {
                $legacy->where('created_by_entity_type', $user->entity_type)
                    ->where('created_by', $user->entity_id);
            });
        });
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Keep open reminder pop-ups in step without them polling: whenever a
     * reminder that is (or was) showing changes or is deleted, its users are
     * told over Reverb. Bulk query updates skip model events, so those callers
     * push themselves (see ReminderService).
     */
    protected static function booted(): void
    {
        static::updated(function (Reminder $reminder) {
            if ($reminder->wasShowable() || $reminder->isShowable()) {
                app(\App\Services\FCH\Common\Reminder\ReminderBroadcaster::class)->updated($reminder);
            }
        });

        static::deleted(function (Reminder $reminder) {
            if ($reminder->wasShowable()) {
                app(\App\Services\FCH\Common\Reminder\ReminderBroadcaster::class)->gone($reminder);
            }
        });
    }

    /**
     * Whether the due-reminder pop-up shows it: fired, or past its time and not
     * yet fired. A silent row (is_reminder false - e.g. the creator's copy of a
     * task only its assignee is reminded of) is listed but never shown.
     */
    public function isShowable(): bool
    {
        return $this->is_reminder !== false
            && self::showable($this->status, $this->reminder_date_time ?? $this->send_at ?? $this->set_date_time);
    }

    /** isShowable() for the values the row had before the current change. */
    public function wasShowable(): bool
    {
        $at = $this->getOriginal('reminder_date_time') ?? $this->getOriginal('send_at') ?? $this->getOriginal('set_date_time');

        return $this->getOriginal('is_reminder') !== false
            && self::showable($this->getOriginal('status'), $at);
    }

    private static function showable(?string $status, $at): bool
    {
        return $status === self::STATUS_SENT
            || ($status === self::STATUS_PENDING && $at && \Illuminate\Support\Carbon::parse($at)->lte(now()));
    }

    /**
     * The login accounts that see this reminder. New rows belong to one User;
     * older rows name the owning entity (Partner, Borrower, Employee...), which
     * several login accounts can share, so each of them sees it.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function recipients(): \Illuminate\Support\Collection
    {
        if ($this->created_by_entity_type === User::class) {
            return User::whereKey($this->created_by)->get();
        }

        return User::where('entity_type', $this->created_by_entity_type)
            ->where('entity_id', $this->created_by)
            ->get();
    }

    public function creator(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'created_by_entity_type', 'created_by');
    }

    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class, 'activity_log_id');
    }

    /**
     * Eager-load the entity (plus the borrower behind a funding request /
     * application) so clientSummary() doesn't query once per reminder.
     */
    public function scopeWithClient($query)
    {
        return $query->with(self::clientRelations());
    }

    public function loadClient(): static
    {
        return $this->load(self::clientRelations());
    }

    private static function clientRelations(): array
    {
        // activityLog.causer: who assigned the task, shown with its status
        return ['activityLog.causer', 'entity' => function (MorphTo $morphTo) {
            $morphTo->morphWith([
                FundingRequest::class => ['borrower'],
                FundingApplication::class => ['borrower'],
            ]);
        }];
    }

    /**
     * Who this reminder is for. Funding requests/applications resolve to
     * their borrower so "Go to Client" lands on the client's own file; the
     * request/application itself is returned as `related`.
     *
     * @return array{client: ?array, related: ?array}
     */
    public function clientSummary(): array
    {
        $entity = $this->entity;
        $related = null;

        if ($entity instanceof FundingRequest) {
            $related = [
                'type' => 'funding_request',
                'id' => $entity->id,
                'label' => 'Funding Request #' . $entity->id,
            ];
            $entity = $entity->borrower;
        } elseif ($entity instanceof FundingApplication) {
            $related = [
                'type' => 'funding_application',
                'id' => $entity->id,
                'label' => 'Funding Application ' . ($entity->application_number ?: '#' . $entity->id),
            ];

            // An application can exist before a borrower account is linked —
            // fall back to the applicant details captured on the application.
            if (!$entity->borrower) {
                return [
                    'client' => [
                        'type' => null,
                        'type_label' => 'Applicant',
                        'id' => null,
                        'name' => $entity->full_name ?: $entity->business_name,
                        'business_name' => $entity->business_name,
                        'email' => $entity->business_email,
                        'phone' => $entity->business_phone_no,
                    ],
                    'related' => $related,
                ];
            }

            $entity = $entity->borrower;
        }

        $client = match (true) {
            $entity instanceof Borrower => [
                'type' => 'borrower',
                'type_label' => 'Client',
                'id' => $entity->id,
                'name' => $entity->full_name ?: $entity->business_name,
                'business_name' => $entity->business_name,
                'email' => $entity->email ?: $entity->business_email,
                'phone' => $entity->phone_number ?: $entity->business_phone,
            ],
            $entity instanceof Lead => [
                'type' => 'lead',
                'type_label' => 'Lead',
                'id' => $entity->id,
                'name' => $entity->contact_person ?: ($entity->company_name ?: $entity->legal_business_name),
                'business_name' => $entity->company_name ?: $entity->legal_business_name,
                'email' => $entity->email,
                'phone' => $entity->phone_number ?: $entity->business_phone,
            ],
            $entity instanceof Partner => [
                'type' => 'partner',
                'type_label' => 'Partner',
                'id' => $entity->id,
                'name' => $entity->full_name ?: $entity->company_name,
                'business_name' => $entity->company_name,
                'email' => $entity->email,
                'phone' => $entity->phone_number,
            ],
            $entity instanceof Employee => [
                'type' => 'employee',
                'type_label' => 'Employee',
                'id' => $entity->id,
                'name' => $entity->full_name,
                'business_name' => $entity->designation,
                'email' => $entity->email,
                'phone' => $entity->phone_number_1 ?: $entity->phone_number_2,
            ],
            $entity instanceof User => $this->userClientSummary($entity),
            default => null,
        };

        return ['client' => $client, 'related' => $related];
    }

    /**
     * A login account (Admin, User, Employee...). When it belongs to an
     * employee/partner/borrower record, link to that record's page since
     * user accounts themselves have no details page.
     */
    private function userClientSummary(User $user): array
    {
        $linkTypes = [
            Employee::class => 'employee',
            Partner::class => 'partner',
            Borrower::class => 'borrower',
        ];
        $linkedType = $linkTypes[$user->entity_type] ?? null;

        return [
            'type' => $linkedType ?? ($user->user_type === User::ADMIN ? 'admin' : 'user'),
            'type_label' => $user->user_type ?: 'User',
            'id' => $linkedType ? $user->entity_id : $user->id,
            'name' => $user->name,
            'business_name' => null,
            'email' => $user->email,
            'phone' => $user->phone_number,
        ];
    }

    public function scopeDue($query)
    {
        // Silent rows (is_reminder false) are listed only, never fired
        return $query->where('status', self::STATUS_PENDING)
            ->where('is_reminder', true)
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

        // Client names are matched word by word so "John Smith" finds a
        // borrower stored as first_name/last_name without a DB-specific CONCAT.
        $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $matchWords = function ($q, array $columns) use ($words) {
            foreach ($words as $word) {
                $q->where(function ($wq) use ($columns, $word) {
                    foreach ($columns as $column) {
                        $wq->orWhere($column, 'like', "%{$word}%");
                    }
                });
            }
        };
        $borrowerColumns = ['first_name', 'last_name', 'business_name', 'email'];

        return $query->where(function ($q) use ($search, $matchWords, $borrowerColumns) {
            $q->where('summary', 'like', "%{$search}%")
              ->orWhere('message', 'like', "%{$search}%")
              ->orWhere('reference', 'like', "%{$search}%")
              ->orWhere('status', 'like', "%{$search}%")
              ->orWhereHasMorph('entity', [Borrower::class], fn ($eq) => $matchWords($eq, $borrowerColumns))
              ->orWhereHasMorph('entity', [Lead::class], fn ($eq) => $matchWords($eq, ['contact_person', 'company_name', 'legal_business_name', 'email']))
              ->orWhereHasMorph('entity', [Partner::class], fn ($eq) => $matchWords($eq, ['full_name', 'company_name', 'email']))
              ->orWhereHasMorph('entity', [Employee::class], fn ($eq) => $matchWords($eq, ['first_name', 'last_name', 'email']))
              ->orWhereHasMorph('entity', [User::class], fn ($eq) => $matchWords($eq, ['name', 'email']))
              ->orWhereHasMorph(
                  'entity',
                  [FundingRequest::class, FundingApplication::class],
                  fn ($eq) => $eq->whereHas('borrower', fn ($bq) => $matchWords($bq, $borrowerColumns))
              );
        });
    }

    /**
     * Whole days in $timezone (the viewer's), turned into UTC bounds for the
     * stored set_date_time / send_at - not the UTC day (BUG-152). An unknown
     * or missing zone falls back to the app's.
     */
    public function scopeDateBetween($query, ?string $startDate, ?string $endDate, ?string $timezone = null)
    {
        if (!$startDate || !$endDate) {
            return $query;
        }

        $timezone = $timezone && in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : config('app.timezone', 'UTC');

        $start = Carbon::parse($startDate, $timezone)->startOfDay()->utc()->format('Y-m-d H:i:s');
        $end = Carbon::parse($endDate, $timezone)->endOfDay()->utc()->format('Y-m-d H:i:s');

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
