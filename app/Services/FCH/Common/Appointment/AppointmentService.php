<?php

namespace App\Services\FCH\Common\Appointment;

use App\Services\FCH\Common\Notification\Notifier;
use Throwable;
use Carbon\Carbon;
use App\Models\User\User;
use Illuminate\Support\Str;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Appointment\Appointment;
use App\Models\Appointment\AppointmentDetail;
use App\Support\Appointment\AppointmentTime;
use App\Services\Global\Datatable\QueryFilter;
use App\Mail\Appointment\AppointmentNotification;
use App\Http\Resources\FCH\Appointment\AppointmentResource;
use App\Services\Global\Microsoft\MicrosoftCalendarService;
use App\Services\Global\Calendly\CalendlyClient;
use App\Services\FCH\Common\Calendly\CalendlyService;
use App\Models\Calendly\CalendlyConnection;
use Illuminate\Validation\ValidationException;
use App\Http\Resources\FCH\Appointment\AppointmentUserResource;
use App\Models\ActivityLog\ActivityLog;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class AppointmentService
{
    use PaginatesApi;

    public function __construct(
        protected MicrosoftCalendarService $microsoftCalendarService,
        protected ActivityLogService $activityLogService,
        protected CalendlyClient $calendlyClient,
    ) {
    }

    /**
     * List Appointments
     */
    public function list(array $filters)
    {
        $query = Appointment::query()->with(['scheduledBy', 'assignedFor.entity']);

        // Filter: Auth user is either the host (scheduler) or the attendee (assigned_for)
        $userId = auth()->id();
        $query->where(function ($q) use ($userId) {
            $q->where('scheduled_by', $userId)
                ->orWhere('assigned_for', $userId);
        });

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('appointment_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['meeting_type'])) {
            $query->where('meeting_type', $filters['meeting_type']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'appointment_date';
        $sortOrder = $filters['sort_order'] ?? 'asc';


        // Retrieve all records without pagination
        $results = $query->orderBy('appointment_date', 'asc')->get();

        return ApiResponse::success(
            AppointmentResource::collection($results),
            'Appointments retrieved successfully.'
        );
    }

    /**
     * Create a new appointment and sync with Microsoft 365.
     */
    public function create(array $data): Appointment
    {
        $this->resolveEntityAndUser($data);

        DB::beginTransaction();

        try {
            $data['appointment_code'] = $this->generateNextAppointmentCode();
            $data['scheduled_by'] = auth()->id();

            $appointment = Appointment::create($data);
            Log::info('[DEMO] Appointment record created in database. Code: ' . $appointment->appointment_code);

            // Log Appointment Creation
            $this->activityLogService->logModelEvent($appointment, ActivityLog::LOG_TYPE_APPOINTMENT_CREATE);

            $appointmentDateOnly = Carbon::parse($data['appointment_date'])->format('Y-m-d');

            AppointmentDetail::create([
                'appointment_id' => $appointment->id,
                'scheduled_by' => $appointment->scheduled_by,
                'assigned_for' => $appointment->assigned_for,
                'appointment_date' => $appointmentDateOnly,
                'status' => AppointmentDetail::STATUS_PENDING,
            ]);
            // Sync with Microsoft 365 (SKIPPED as per requirement)
            /*
             */
            $syncResult = false;

            // Send Email Notification to Assigned User
            $this->sendNotification($appointment, 'created');

            DB::commit();

            // Add sync status to the object temporarily for the controller to read
            $appointment->sync_successful = $syncResult;

            return $appointment;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('[DEMO] Appointment creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Generate the next accurate sequential appointment code.
     * Includes soft-deleted records to prevent duplicate codes.
     */
    public function generateNextAppointmentCode(): string
    {
        // Include soft-deleted records to get the true last code
        $lastAppointment = Appointment::withTrashed()
            ->orderBy('id', 'desc')
            ->first();

        if (!$lastAppointment) {
            return 'APP-0001';
        }

        // Extract the number from the code (e.g., from APP-0005 extract 5)
        $lastCode = $lastAppointment->appointment_code;
        $lastNumber = (int) substr($lastCode, 4);
        $nextNumber = $lastNumber + 1;

        // Pad with zeros to maintain 'APP-0000' format
        return 'APP-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Update an appointment and sync with Microsoft 365.
     */
    public function update(Appointment $appointment, array $data): Appointment
    {
        // A Calendly meeting's new title or time is made in Calendly first (it replaces
        // the meeting there); if Calendly refuses, nothing changes here either.
        if ($appointment->isFromCalendly()) {
            $data = array_merge($data, app(CalendlyService::class)->moveMeeting($appointment, $data));
        }
        $this->resolveEntityAndUser($data);

        DB::beginTransaction();
        try {
            $appointment->update($data);

            // Log Appointment Update
            $this->activityLogService->logModelEvent($appointment, ActivityLog::LOG_TYPE_APPOINTMENT_UPDATE);

            // Sync with Microsoft 365 (SKIPPED as per requirement)
            /*
            $syncResult = $this->syncWithMicrosoft($appointment);
            */
            $syncResult = false;

            // Send Email Notification to Assigned User
            $this->sendNotification($appointment, 'updated');

            DB::commit();

            $appointment->sync_successful = $syncResult;

            return $appointment->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Appointment update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Cancel an appointment: kept, marked cancelled, and the other party - the
     * organiser when the attendee cancels, else the attendee - is emailed once
     * (R2 BUG-046).
     */
    public function cancel(Appointment $appointment, User $by): Appointment
    {
        // In Calendly first: if Calendly refuses, the meeting stays as it is here too.
        $this->cancelInCalendly($appointment);

        $appointment->update(['status' => Appointment::STATUS_CANCELLED]);
        $this->activityLogService->logModelEvent($appointment, ActivityLog::LOG_TYPE_APPOINTMENT_UPDATE);

        $otherParty = (int) $appointment->assigned_for === (int) $by->id
            ? $appointment->scheduledBy
            : $appointment->assignedFor;
        $this->sendNotification($appointment, 'deleted', $otherParty);

        return $appointment->fresh();
    }

    /**
     * Delete an appointment and remove from Microsoft 365.
     */
    public function delete(Appointment $appointment): bool
    {
        // An upcoming Calendly meeting is cancelled there before it goes from here.
        if ($appointment->isCancellable()) {
            $this->cancelInCalendly($appointment);
        }

        DB::beginTransaction();
        try {
            // Delete from Microsoft 365 if synced (SKIPPED as per requirement)
            /*
            if ($appointment->microsoft_event_id && $appointment->assignedFor) {
                $this->microsoftCalendarService->deleteEvent(
                    $appointment->assignedFor->email,
                    $appointment->microsoft_event_id
                );
                Log::info('[MS GRAPH] Event deleted from Microsoft 365', [
                    'appointment_id' => $appointment->id,
                    'event_id' => $appointment->microsoft_event_id
                ]);
            }
            */

            // Soft delete related appointment details
            $detailsCount = $appointment->details()->count();
            if ($detailsCount > 0) {
                $appointment->details()->delete();
                Log::info('[APPOINTMENT] Deleted appointment details', [
                    'appointment_id' => $appointment->id,
                    'details_count' => $detailsCount
                ]);
            }

            // Soft delete the appointment
            $appointment->delete();

            // Log Appointment Deletion
            $this->activityLogService->logModelEvent($appointment, ActivityLog::LOG_TYPE_APPOINTMENT_DELETE);
            Log::info('[APPOINTMENT] Appointment deleted successfully', [
                'appointment_id' => $appointment->id,
                'appointment_code' => $appointment->appointment_code
            ]);

            // Send Email Notification to Assigned User
            $this->sendNotification($appointment, 'deleted');

            DB::commit();
            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('[APPOINTMENT] Deletion failed', [
                'appointment_id' => $appointment->id ?? null,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Cancels a Calendly meeting in Calendly (so both people get Calendly's
     * cancellation, and the slot is free again). Nothing to do for an FCH
     * meeting, one already cancelled, or when Calendly is no longer connected.
     * Throws CalendlyException when Calendly refuses.
     */
    protected function cancelInCalendly(Appointment $appointment): void
    {
        if (!$appointment->isFromCalendly() || $appointment->status === Appointment::STATUS_CANCELLED) {
            return;
        }

        // The other person's copy in their own Calendly goes too (never holds up the cancel).
        app(CalendlyService::class)->cancelMirror($appointment);

        // The Calendly it came from (its owner's own) - not the caller's.
        $connection = CalendlyConnection::find($appointment->calendly_connection_id);
        if (!$connection?->isActive()) {
            Log::warning('[CALENDLY] Cancelled in FCH only - its Calendly is not connected', ['appointment_id' => $appointment->id]);

            return;
        }

        try {
            $this->calendlyClient->forConnection($connection)->post($appointment->calendly_event_uri . '/cancellation', [
                'reason' => __('calendly.cancel_reason'),
            ]);
        } catch (\App\Services\Global\Calendly\CalendlyException $e) {
            // Gone from Calendly already (404), or on another Calendly account than the one
            // now connected (403): nothing this connection can cancel - done in FCH only.
            if (!in_array($e->calendlyStatus(), [403, 404], true)) {
                throw $e;
            }
            Log::warning('[CALENDLY] Cancelled in FCH only - not on the connected Calendly account', [
                'appointment_id' => $appointment->id,
                'status' => $e->calendlyStatus(),
            ]);
        }
    }

    /**
     * Sync appointment with Microsoft 365 Calendar.
     * @return bool Returns true if sync was successful or not needed, false on error.
     */
    protected function syncWithMicrosoft(Appointment $appointment): bool
    {

        // $serviceEmail = config('services.microsoft.calendar_service_email');
        $targetEmail = ($appointment->assignedFor->email ?? null);
        if (!$targetEmail) {
            Log::warning('[MS Graph] No target email found for sync.');
            return false;
        }

        // Logic check: If target is Gmail/External and no Service Mailbox is set, we CANNOT sync.
        $isExternal = preg_match('/@(gmail|yahoo|outlook\.com|hotmail)\.com$/i', $targetEmail);
        if ($isExternal && !$serviceEmail) {
            Log::warning("[MS Graph] Skipping sync for external account {$targetEmail} because MS_CALENDAR_SERVICE_EMAIL is not configured.");
            return false;
        }

        $eventData = $this->formatMicrosoftEvent($appointment);

        if ($appointment->microsoft_event_id) {
            return $this->microsoftCalendarService->updateEvent($targetEmail, $appointment->microsoft_event_id, $eventData);
        } else {
            $eventId = $this->microsoftCalendarService->createEvent($targetEmail, $eventData);
            if ($eventId) {
                return $appointment->update(['microsoft_event_id' => $eventId]);
            }
        }

        return false;
    }

    /**
     * Format appointment data for Microsoft Graph API.
     */
    protected function formatMicrosoftEvent(Appointment $appointment): array
    {
        $start = Carbon::parse($appointment->appointment_date);
        $end = (clone $start)->addMinutes($appointment->duration_minutes);

        return [
            'subject' => $appointment->subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => ($appointment->description ?: 'No description provided.') . "<br><br><b>Ref: {$appointment->appointment_code}</b>",
            ],
            'start' => [
                'dateTime' => $start->format('Y-m-d\TH:i:s'),
                'timeZone' => $appointment->timezone ?: 'UTC',
            ],
            'end' => [
                'dateTime' => $end->format('Y-m-d\TH:i:s'),
                'timeZone' => $appointment->timezone ?: 'UTC',
            ],
            'location' => [
                'displayName' => $appointment->location ?: 'Online Meeting',
            ],
            'attendees' => [
                [
                    'emailAddress' => [
                        'address' => $appointment->assignedFor->email,
                        'name' => $appointment->assignedFor->full_name ?? $appointment->assignedFor->email,
                    ],
                    'type' => 'required',
                ],
            ],
            'isOnlineMeeting' => in_array($appointment->meeting_type, [
                Appointment::MEETING_TYPE_INITIAL_CONSULTATION,
                Appointment::MEETING_TYPE_FOLLOWUP_MEETING,
                Appointment::MEETING_TYPE_TECHNICAL_SUPPORT,
                Appointment::MEETING_TYPE_FEEDBACK_SESSION,
                Appointment::MEETING_TYPE_BUSINESS_REVIEW,
                Appointment::MEETING_TYPE_PARTNERSHIP_MEETING,
            ]),
        ];
    }

    /**
     * Send email notification to the assigned user with business info - or to
     * $recipient, when someone else is to be told (a cancellation by the
     * attendee goes to the organiser).
     */
    protected function sendNotification(Appointment $appointment, string $action, ?User $recipient = null): void
    {
        $recipient ??= $appointment->assignedFor;
        if (!$recipient) {
            return;
        }

        try {
            $user = auth()->user();
            $partner = $user ? $user->partner : $appointment->scheduledBy->partner;
            $partnerId = $partner?->id;

            // Fallback empty if no partner found (e.g. system admin?)
            $businessInfo = [
                'company_name' => $partner->company_name ?? 'Our Company',
                'company_logo' => $partner->company_logo ?? 'logo.png',
                'email' => $partner->email ?? '',
                'phone_number' => $partner->phone_number ?? '',
                'address_line_1' => $partner->address_line_1 ?? '',
            ];

            try {
                $assignee = $recipient;

                // Each portal has its own appointments page, hence a type per audience.
                app(Notifier::class)->send(
                    $assignee->user_type === User::BORROWER ? 'borrower.appointment.changed' : 'appointment.changed',
                    $assignee,
                    [
                        'action' => ['created' => 'scheduled', 'deleted' => 'cancelled'][$action] ?? $action,
                        'title' => $appointment->subject,
                        'date' => $appointment->appointment_date
                            ? Carbon::parse($appointment->appointment_date)->format('M j, Y')
                            : '',
                    ],
                    mail: (new AppointmentNotification($appointment, $action, $businessInfo, $partnerId, $assignee))->to($assignee->email)
                );
                Log::info('[EMAIL] Appointment ' . $action . ' notification sent to user ' . $assignee->id);
            } catch (\Exception $e) {
                Log::error('[EMAIL] Failed to send appointment ' . $action . ' notification', ['error' => $e->getMessage()]);
            }
        } catch (Throwable $e) {
            Log::error('[EMAIL] Failed to send appointment ' . $action . ' notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if a time slot is available for both users on a specific date.
     */
    /**
     * The viewer's calendar day holding $date, as the UTC range appointments
     * are stored in - so a day in Dhaka finds what is stored on the UTC day
     * before (BUG-080). $zone is the form's zone, else the viewer's.
     *
     * @return array{0: string, 1: string}
     */
    private function viewerDayInUtc(string $date, ?string $zone): array
    {
        $zone = AppointmentTime::zone($zone) ?? AppointmentTime::viewerZone();
        $day = Carbon::parse($date, $zone);

        return [
            $day->copy()->startOfDay()->utc()->format(AppointmentTime::STORED_FORMAT),
            $day->copy()->endOfDay()->utc()->format(AppointmentTime::STORED_FORMAT),
        ];
    }

    /**
     * Check for duplicate appointments.
     */
    public function checkDuplicate(array $data)
    {
        $this->resolveEntityAndUser($data);

        $day = $this->viewerDayInUtc($data['appointment_date'], $data['timezone'] ?? null);
        $assignedFor = $data['assigned_for'] ?? null;
        $scheduledBy = auth()->id();

        // Check conflicts for Scheduler
        $schedulerConflicts = Appointment::whereBetween('appointment_date', $day)
            ->where(function ($q) use ($scheduledBy) {
                $q->where('scheduled_by', $scheduledBy)
                    ->orWhere('assigned_for', $scheduledBy);
            })
            ->with(['scheduledBy', 'assignedFor'])
            ->get();

        $assigneeConflicts = collect();
        if ($assignedFor) {
            $assigneeConflicts = Appointment::whereBetween('appointment_date', $day)
                ->where(function ($q) use ($assignedFor) {
                    $q->where('scheduled_by', $assignedFor)
                        ->orWhere('assigned_for', $assignedFor);
                })
                ->with(['scheduledBy', 'assignedFor'])
                ->get();
        }

        return [
            'scheduler_conflicts' => $schedulerConflicts,
            'assignee_conflicts' => $assigneeConflicts,
        ];
    }

    /**
     * Get busy slots (appointments) for a specific date.
     */
    public function getBusySlots(array $data)
    {
        $day = $this->viewerDayInUtc($data['date'], $data['timezone'] ?? null);
        $assignedFor = $data['assigned_for'] ?? null;
        $scheduledBy = auth()->id();
        $excludeId = $data['exclude_appointment_id'] ?? null;


        $schedulerBusySlots = Appointment::whereBetween('appointment_date', $day)
            ->where(function ($q) use ($scheduledBy) {
                $q->where('scheduled_by', $scheduledBy)
                    ->orWhere('assigned_for', $scheduledBy);
            })
            ->when($excludeId, function ($q) use ($excludeId) {
                $q->where('id', '!=', $excludeId);
            })
            ->get(['id', 'appointment_date', 'duration_minutes', 'appointment_end_date']);

        $assigneeBusySlots = collect();
        if ($assignedFor) {
            $assigneeBusySlots = Appointment::whereBetween('appointment_date', $day)
                ->where(function ($q) use ($assignedFor) {
                    $q->where('scheduled_by', $assignedFor)
                        ->orWhere('assigned_for', $assignedFor);
                })
                ->when($excludeId, function ($q) use ($excludeId) {
                    $q->where('id', '!=', $excludeId);
                })
                ->get(['id', 'appointment_date', 'duration_minutes', 'appointment_end_date']);
        }

        return [
            'scheduler_busy_slots' => $schedulerBusySlots,
            'assignee_busy_slots' => $assigneeBusySlots,
        ];
    }

    /**
     * Get all users list without pagination for appointment selection.
     */
    public function getSelectableUsers(array $data)
    {
        $query = $this->selectableUsersQuery(Auth::user(), $data);

        $results = $query->with('entity')->get(['id', 'name', 'entity_type', 'entity_id', 'user_type']);


        return ApiResponse::success(
            AppointmentUserResource::collection($results),
            __('appointment.users_selection_retrieved')
        );
    }

    /**
     * Whether $caller may book a meeting with the user $userId: the user is on
     * the caller's bookable-people list, with the meeting-with switch either
     * way. The list was scoped but the write took any user id (findings O20).
     */
    public function canBookWith(User $caller, int $userId): bool
    {
        foreach ([[], ['meeting_with' => 1], ['meeting_with_host' => 1]] as $flags) {
            $bookable = $this->selectableUsersQuery($caller, $flags)->select('users.id');

            if (User::whereKey($userId)->whereIn('id', $bookable)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the booking's entity is the attendee's own - the portals send the
     * picked user's entity; anything else would tie the meeting to a record
     * the attendee is not (findings O20).
     */
    public function entityIsAttendees(?string $entityType, $entityId, int $userId): bool
    {
        $normalizedType = (new Appointment(['entity_type' => $entityType]))->entity_type;

        return User::whereKey($userId)
            ->where('entity_type', $normalizedType)
            ->where('entity_id', $entityId)
            ->exists();
    }

    /**
     * The users $user may book with - the bookable-people list.
     */
    private function selectableUsersQuery(User $user, array $data): Builder
    {
        if ($user->entity_type == Borrower::class) {
            $query = User::where('partner_id', $user->partner_id);
            $borrower = $user->entity;
            if ($borrower) {
                $query->where('entity_type', Partner::class)->orWhere('id', $borrower->assign_id);
            }
        } else {

            $isHost = $user->partner?->is_host;

            if ($isHost) {
                // Admin Partner

                if ($user->entity_type == Partner::class) {
                    $query = User::query();
                    if (isset($data['meeting_with']) && $data['meeting_with']) {
                        $query->where('id', '!=', $user->id)->where('entity_type', Partner::class);
                    } else {
                        $query->where('partner_id', $user->partner_id)->where(function ($q) {
                            $q->where('entity_type', '!=', Partner::class)
                                ->orWhereNull('entity_type');
                        });
                    }
                } else {
                    $query = User::where('partner_id', $user->partner_id)->where(fn($qn) => $qn->where('entity_type', Partner::class)->orWhere('entity_type', Borrower::class)->wherehas('borrower', fn($q) => $q->where('assign_id', $user->id)));
                }

            } else {
                $query = User::query();

                if ($user->entity_type == Partner::class) {
                    if (isset($data['meeting_with_host']) && $data['meeting_with_host']) {
                        $query->where('entity_type', Partner::class)->where('user_type', User::ADMIN);
                    } else {
                        $query->where('partner_id', $user->partner_id)->where(function ($q) {
                            $q->where('entity_type', '!=', Partner::class)
                                ->orWhereNull('entity_type');
                        });
                    }
                } else {
                    $query = User::where('partner_id', $user->partner_id)->where('entity_type', Partner::class)->orWhere(fn($qn) => $qn->where('entity_type', Borrower::class)->wherehas('borrower', fn($q) => $q->where('assign_id', $user->id)));
                }


            }
        }

        return $query;
    }

    /**
     * Resolve entity_type, entity_id, and assigned_for (user_id) based on provided data.
     */
    private function resolveEntityAndUser(array &$data): void
    {
        if (array_key_exists('entity_type', $data) && empty(trim($data['entity_type'] ?? ''))) {
            $data['entity_type'] = null;
        }

        if (array_key_exists('entity_type', $data) && is_null($data['entity_type'])) {
            $data['entity_id'] = null;
            return;
        }

        if (!empty($data['entity_type']) && !empty($data['entity_id'])) {
            $temp = new Appointment(['entity_type' => $data['entity_type']]);
            $normalizedType = $temp->entity_type;

            $data['entity_type'] = $normalizedType;

            if (empty($data['assigned_for'])) {
                $user = User::where('entity_type', $normalizedType)
                    ->where('entity_id', $data['entity_id'])
                    ->first();

                if ($user) {
                    $data['assigned_for'] = $user->id;
                }
            }
        }
    }
}
