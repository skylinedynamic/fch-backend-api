<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Appointment;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\FCH\Common\Appointment\StoreAppointmentRequest;
use App\Http\Requests\V1\FCH\Common\Appointment\UpdateAppointmentRequest;
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
     * Display a listing of appointments.
     */
    public function index(Request $request)
    {
        return $this->appointmentService->list($request->all());
    }

    /**
     * Store a newly created appointment.
     */
    public function store(StoreAppointmentRequest $request)
    {
        $appointment = $this->appointmentService->create($request->validated());
        $message = $appointment->sync_successful 
            ? 'Appointment created and synced successfully.' 
            : 'Appointment created successfully, but Microsoft 365 sync was skipped (External Email).';

        return ApiResponse::success(new AppointmentResource($appointment), $message);
    }

    /**
     * Display the specified appointment.
     */
    public function show($id)
    {
        $appointment = Appointment::with(['scheduledBy', 'assignedFor'])->findOrFail($id);
        return ApiResponse::success(new AppointmentResource($appointment), 'Appointment details retrieved.');
    }

    /**
     * Update the specified appointment.
     */
    public function update(UpdateAppointmentRequest $request, $id)
    {
        $appointment = Appointment::findOrFail($id);
        $updated = $this->appointmentService->update($appointment, $request->validated());
        $message = $updated->sync_successful 
            ? 'Appointment updated and synced successfully.' 
            : 'Appointment updated successfully, but Microsoft 365 sync was skipped or failed.';

        return ApiResponse::success(new AppointmentResource($updated), $message);
    }

    /**
     * Remove the specified appointment.
     */
    public function destroy($id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->appointmentService->delete($appointment);
        return ApiResponse::success(null, 'Appointment deleted successfully.');
    }

    /**
     * Delete multiple appointments.
     */
    public function multipleDelete(Request $request)
    {
        $ids = $request->input('ids', []);
        foreach ($ids as $id) {
            $appointment = Appointment::find($id);
            if ($appointment) {
                $this->appointmentService->delete($appointment);
            }
        }
        return ApiResponse::success(null, 'Selected appointments deleted successfully.');
    }
}
