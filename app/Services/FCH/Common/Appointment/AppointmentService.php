<?php

namespace App\Services\FCH\Common\Appointment;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Appointment\AppointmentResource;
use App\Models\Appointment\Appointment;
use App\Models\User\User;
use App\Services\Global\Microsoft\MicrosoftCalendarService;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AppointmentService
{
    use PaginatesApi;

    protected $microsoftCalendarService;

    public function __construct(MicrosoftCalendarService $microsoftCalendarService)
    {
        $this->microsoftCalendarService = $microsoftCalendarService;
    }

    /**
     * List appointments with filtering and pagination.
     */
    public function list(array $filters)
    {
        $query = Appointment::query()->with(['scheduledBy', 'assignedFor']);

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
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? []
        );

        $perPage = $filters['per_page'] ?? 10;
        $page = $filters['page'] ?? 1;

        $paginator = $this->paginateBuilderWith($query, (int)$perPage, (int)$page);

        return $this->respondWithPaginationResource(
            $paginator,
            AppointmentResource::class,
            'Appointments retrieved successfully.'
        );
    }

    /**
     * Create a new appointment and sync with Microsoft 365.
     */
    public function create(array $data): Appointment
    {
        Log::info('[DEMO] Starting appointment creation process...');
        DB::beginTransaction();
        try {
            $data['appointment_code'] = $this->generateNextAppointmentCode();
            $data['scheduled_by'] = auth()->id();
            
            $appointment = Appointment::create($data);
            Log::info('[DEMO] Appointment record created in database. Code: ' . $appointment->appointment_code);

            // Sync with Microsoft 365
            Log::info('[DEMO] Initiating M365 synchronization...');
            $syncResult = $this->syncWithMicrosoft($appointment);

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
     */
    public function generateNextAppointmentCode(): string
    {
        $lastAppointment = Appointment::orderBy('id', 'desc')->first();
        
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
        DB::beginTransaction();
        try {
            $appointment->update($data);

            // Sync with Microsoft 365
            $syncResult = $this->syncWithMicrosoft($appointment);

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
            if ($appointment->microsoft_event_id && $appointment->assignedFor) {
                $this->microsoftCalendarService->deleteEvent(
                    $appointment->assignedFor->email,
                    $appointment->microsoft_event_id
                );
            }

            $appointment->delete();
            DB::commit();
            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Appointment deletion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Sync appointment with Microsoft 365 Calendar.
     * @return bool Returns true if sync was successful or not needed, false on error.
     */
    protected function syncWithMicrosoft(Appointment $appointment): bool
    {
        $serviceEmail = config('services.microsoft.calendar_service_email');
        $targetEmail = $serviceEmail ?: ($appointment->assignedFor->email ?? null);

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
            'isOnlineMeeting' => $appointment->meeting_type === Appointment::MEETING_TYPE_ONLINE,
        ];
    }
}
