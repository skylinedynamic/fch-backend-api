<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Appointment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Api\V1\FCH\Common\Appointment\UpdateAppointmentRequest;
use App\Http\Requests\Api\V1\FCH\Common\Appointment\CheckAvailabilityRequest;
use App\Http\Resources\FCH\Appointment\AppointmentResource;
use App\Models\Appointment\Appointment;
use App\Services\FCH\Common\Appointment\AppointmentService;
use App\Services\Global\Calendly\CalendlyException;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    protected $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->appointmentService = $appointmentService;
    }

    /**
     * Appointment List
     */
    public function index(Request $request)
    {
        $this->checkWhiteLabelConfiguration();
        return $this->appointmentService->list($request->all());
    }

    /**
     * Appointment Store
     */
    public function store(StoreAppointmentRequest $request)
    {
        $this->checkWhiteLabelConfiguration();
        $appointment = $this->appointmentService->create($request->validated());

        return ApiResponse::success(new AppointmentResource($appointment), __('appointment.created_synced'));
    }

    /**
     * Appointment Show
     */
    public function show($id)
    {
        $appointment = $this->ownAppointment()->with(['scheduledBy', 'assignedFor.entity'])->findOrFail($id);
        return ApiResponse::success(new AppointmentResource($appointment), __('appointment.retrieved'));
    }

    /**
     * Appointment Update
     */
    public function update(UpdateAppointmentRequest $request, $id)
    {
        $this->checkWhiteLabelConfiguration();
        $appointment = $this->ownAppointment()->findOrFail($id);
        $updated = $this->appointmentService->update($appointment, $request->validated());
    
        return ApiResponse::success(new AppointmentResource($updated), __('appointment.updated_synced'));
    }

    /**
     * Appointment Delete
     */
    public function destroy($id)
    {
        $this->checkWhiteLabelConfiguration();
        $appointment = $this->ownAppointment()->findOrFail($id);

        try {
            $this->appointmentService->delete($appointment);
        } catch (CalendlyException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus());
        }

        return ApiResponse::success(null, __('appointment.deleted'));
    }

    /**
     * Cancel an upcoming appointment: its organiser or the person it is with.
     * A client can cancel one the host booked with them, which they cannot
     * edit or delete (R2 BUG-046). The other party is emailed.
     */
    public function cancel($id)
    {
        $appointment = $this->ownAppointment()->with(['scheduledBy', 'assignedFor'])->findOrFail($id);

        if (!$appointment->isCancellable()) {
            return ApiResponse::error(__('appointment.cannot_cancel'), 422);
        }

        try {
            $cancelled = $this->appointmentService->cancel($appointment, auth()->user());
        } catch (CalendlyException $e) {
            // Calendly refused, so it is not cancelled here either.
            return ApiResponse::error($e->getMessage(), $e->httpStatus());
        }

        return ApiResponse::success(new AppointmentResource($cancelled), __('appointment.cancelled'));
    }

    /**
     * The appointments the signed-in user is on - scheduled by them or with
     * them - the same rule the appointment list applies.
     */
    private function ownAppointment()
    {
        $userId = auth()->id();

        return Appointment::where(function ($q) use ($userId) {
            $q->where('scheduled_by', $userId)->orWhere('assigned_for', $userId);
        });
    }

    /**
     * Check Appointment Availability
     */
    public function checkAvailability(CheckAvailabilityRequest $request)
    {
        $result = $this->appointmentService->checkDuplicate($request->all());
        
        $schedulerConflicts = $result['scheduler_conflicts'];
        $assigneeConflicts = $result['assignee_conflicts'];

        if ($schedulerConflicts->isNotEmpty() || $assigneeConflicts->isNotEmpty()) {
            
            $formatConflict = function ($conflicts) {
                return $conflicts->map(function ($appointment) {
                    // Stored in UTC; compared by the form in the viewer's own zone.
                    $start = \Carbon\Carbon::parse(\App\Support\Appointment\AppointmentTime::forViewer($appointment->appointment_date));
                    $end = $start->copy()->addMinutes($appointment->duration_minutes);
                    
                    return [
                        'id' => $appointment->id,
                        'date' => $start->format('Y-m-d'),
                        'start_time' => $start->format('H:i'),
                        'end_time' => $end->format('H:i'),
                        'duration_minutes' => $appointment->duration_minutes,
                    ];
                });
            };

            return ApiResponse::success([
                'available' => false,
                'scheduler_conflicts' => $formatConflict($schedulerConflicts),
                'assignee_conflicts' => $formatConflict($assigneeConflicts),
            ], __('appointment.duplicate_found'));
        }

        return ApiResponse::success([
            'available' => true
        ], __('appointment.available'));
    }

    /**
     * Get Selectable Users
     */
    public function selectableUsers(Request $request)
    {
        return $this->appointmentService->getSelectableUsers($request->all());
    }
}
