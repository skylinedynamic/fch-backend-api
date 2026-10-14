<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Appointment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Api\V1\FCH\Common\Appointment\UpdateAppointmentRequest;
use App\Http\Requests\Api\V1\FCH\Common\Appointment\CheckAvailabilityRequest;
use App\Http\Resources\FCH\Appointment\AppointmentResource;
use App\Models\Appointment\Appointment;
use App\Services\FCH\Common\Appointment\AppointmentService;
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
        $appointment = Appointment::with(['scheduledBy', 'assignedFor.entity'])->findOrFail($id);

        if (!$this->isParticipant($appointment)) {
            return ApiResponse::forbidden();
        }

        return ApiResponse::success(new AppointmentResource($appointment), __('appointment.retrieved'));
    }

    /**
     * Appointment Update
     */
    public function update(UpdateAppointmentRequest $request, $id)
    {
        $this->checkWhiteLabelConfiguration();
        $appointment = Appointment::findOrFail($id);

        if (!$this->isParticipant($appointment)) {
            return ApiResponse::forbidden();
        }

        $updated = $this->appointmentService->update($appointment, $request->validated());
    
        return ApiResponse::success(new AppointmentResource($updated), __('appointment.updated_synced'));
    }

    /**
     * Appointment Delete
     */
    public function destroy($id)
    {
        $this->checkWhiteLabelConfiguration();
        $appointment = Appointment::findOrFail($id);

        if (!$this->isParticipant($appointment)) {
            return ApiResponse::forbidden();
        }

        $this->appointmentService->delete($appointment);
        return ApiResponse::success(null, __('appointment.deleted'));
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
                    $start = \Carbon\Carbon::parse($appointment->appointment_date);
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

    /**
     * The same rule the appointment list uses: only the person who scheduled
     * it and the person it is with. Opening, changing or deleting one by id
     * used to skip this, so anyone signed in could reach any appointment.
     */
    private function isParticipant(Appointment $appointment): bool
    {
        $userId = auth()->id();

        return $userId !== null
            && ((int) $appointment->scheduled_by === (int) $userId
                || (int) $appointment->assigned_for === (int) $userId);
    }
}
