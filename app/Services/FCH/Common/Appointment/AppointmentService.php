<?php

namespace App\Services\FCH\Common\Appointment;

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
use Illuminate\Support\Facades\Mail;
use App\Models\Appointment\Appointment;
use App\Models\Appointment\AppointmentDetail;
use App\Services\Global\Datatable\QueryFilter;
use App\Mail\Appointment\AppointmentNotification;
use App\Http\Resources\FCH\Appointment\AppointmentResource;
use App\Services\Global\Microsoft\MicrosoftCalendarService;
use App\Http\Resources\FCH\Appointment\AppointmentUserResource;
use Illuminate\Support\Facades\Auth;

class AppointmentService
{
    use PaginatesApi;

    protected $microsoftCalendarService;

    public function __construct(MicrosoftCalendarService $microsoftCalendarService)
    {
        $this->microsoftCalendarService = $microsoftCalendarService;
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
            $isAvailable = $this->checkTimeSlotAvailability(
                $data['appointment_date'],
                auth()->id(),
                $data['assigned_for']
            );
            $data['appointment_code'] = $this->generateNextAppointmentCode();
            $data['scheduled_by'] = auth()->id();

            $appointment = Appointment::create($data);
            Log::info('[DEMO] Appointment record created in database. Code: ' . $appointment->appointment_code);

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
        $this->resolveEntityAndUser($data);

        DB::beginTransaction();
        try {
            $appointment->update($data);

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
     * Delete an appointment and remove from Microsoft 365.
     */
    public function delete(Appointment $appointment): bool
    {
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
     * Send email notification to the assigned user with business info.
     */
    protected function sendNotification(Appointment $appointment, string $action): void
    {
        if (!$appointment->assignedFor) {
            return;
        }

        try {
            $user = auth()->user();
            $partner = $user ? $user->partner : $appointment->scheduledBy->partner;

            // Fallback empty if no partner found (e.g. system admin?)
            $businessInfo = [
                'company_name' => $partner->company_name ?? 'Our Company',
                'company_logo' => $partner->company_logo ?? 'logo.png',
                'email' => $partner->email ?? '',
                'phone_number' => $partner->phone_number ?? '',
                'address_line_1' => $partner->address_line_1 ?? '',
            ];

            Mail::to($appointment->assignedFor->email)->send(
                new AppointmentNotification($appointment, $action, $businessInfo)
            );

            Log::info('[EMAIL] Appointment ' . $action . ' notification sent to ' . $appointment->assignedFor->email);
        } catch (Throwable $e) {
            Log::error('[EMAIL] Failed to send appointment ' . $action . ' notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if a time slot is available for both users on a specific date.
     */
    protected function checkTimeSlotAvailability(string $appointmentDate, int $scheduledBy, int $assignedFor): bool
    {
        $appointmentDateOnly = Carbon::parse($appointmentDate)->format('Y-m-d');

        $conflictCount = AppointmentDetail::where('appointment_date', $appointmentDateOnly)
            ->where(function ($query) use ($scheduledBy, $assignedFor) {
                $query->where('scheduled_by', $scheduledBy)
                    ->orWhere('scheduled_by', $assignedFor)
                    ->orWhere('assigned_for', $scheduledBy)
                    ->orWhere('assigned_for', $assignedFor);
            })
            ->whereIn('status', [
                AppointmentDetail::STATUS_PENDING,
                AppointmentDetail::STATUS_CONFIRMED,
            ])
            ->count();

        return $conflictCount === 0;
    }

    /**
     * Check for duplicate appointments.
     */
    public function checkDuplicate(array $data)
    {
        $this->resolveEntityAndUser($data);

        $appointmentDate = Carbon::parse($data['appointment_date'])->format('Y-m-d');
        $assignedFor = $data['assigned_for'] ?? null;
        $scheduledBy = auth()->id();

        // Check conflicts for Scheduler
        $schedulerConflicts = Appointment::whereDate('appointment_date', $appointmentDate)
            ->where(function ($q) use ($scheduledBy) {
                $q->where('scheduled_by', $scheduledBy)
                    ->orWhere('assigned_for', $scheduledBy);
            })
            ->with(['scheduledBy', 'assignedFor'])
            ->get();

        $assigneeConflicts = collect();
        if ($assignedFor) {
            $assigneeConflicts = Appointment::whereDate('appointment_date', $appointmentDate)
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
        $date = Carbon::parse($data['date'])->format('Y-m-d'); // Y-m-d
        $assignedFor = $data['assigned_for'] ?? null;
        $scheduledBy = auth()->id();
        $excludeId = $data['exclude_appointment_id'] ?? null;


        $schedulerBusySlots = Appointment::whereDate('appointment_date', $date)
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
            $assigneeBusySlots = Appointment::whereDate('appointment_date', $date)
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
        $user = Auth::user();


        if ($user->entity_type == Borrower::class) {
            $query = User::where('partner_id', $user->partner_id);
            $borrower = $user->entity;
            if ($borrower) {
                $query->where(function ($q) {
                    $q->where('entity_type', '!=', Borrower::class)
                        ->orWhereNull('entity_type');
                });
            }
        } else {

            $isHost = $user->partner?->is_host;

            if ($isHost == 1) {
                // Admin Partner
                $query = User::query();

                if (isset($data['meeting_with']) &&  $data['meeting_with']) {
                    $query->where('user_type', User::PARTNER);
                } else {
                    $query->where('entity_type', '!=', Partner::class)
                        ->orWhereNull('entity_type');
                }
            } else {
                $query = User::query();

                if (isset($data['meeting_with_host']) && $data['meeting_with_host']) {
                    $query->where('user_type', User::ADMIN)->where('entity_type', '!=', Borrower::class);
                } else {
                    $query->where('partner_id', $user->partner_id)->where(function ($q) {
                        $q->where('entity_type', '!=', Partner::class)
                            ->orWhereNull('entity_type');
                    });
                }
            }
        }

        $results = $query->with('entity')->get(['id', 'name', 'entity_type', 'entity_id', 'user_type']);


        return ApiResponse::success(
            AppointmentUserResource::collection($results),
            __('appointment.users_selection_retrieved')
        );
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
