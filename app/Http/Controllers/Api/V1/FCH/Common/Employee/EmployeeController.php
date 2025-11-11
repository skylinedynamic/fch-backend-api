<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Employee;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\Controller;
use App\Models\Employee\Employee;
use App\Services\FCH\Common\Employee\EmployeeService;
use App\Http\Requests\Api\V1\FCH\Common\Employee\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\FCH\Common\Employee\UpdateEmployeeRequest;
use App\Http\Resources\FCH\Employee\EmployeeResource;
use App\Services\Global\Notification\Mail\AccountCreateNotificationMail;

class EmployeeController extends Controller
{
    public function __construct(protected EmployeeService $employeeService)
    {
    }

    public function index(Request $request)
    {
        $businessId = $request->user()->business_id;

        $filters = [
            'search' => $request->get('search'),
            'status' => $request->get('status'),
            'department' => $request->get('department'),
        ];

        $employees = $this->employeeService->list(
            $businessId,
            $request->get('per_page', 10),
            $filters
        );

        return response()->json([
            'status' => true,
            'message' => __('employee.employee_listed'),
            'data' => EmployeeResource::collection($employees),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    /**
     * Store new employee (optionally create user account and assign role)
     */
    public function store(StoreEmployeeRequest $request)
    {
        $businessId = $request->user()->business_id;
        $businessInfo = $request->user()->business;

        // ✅ Include all extended employee fields
        $employeeData = $request->only([
            'first_name', 'last_name', 'email', 'phone_number',
            'designation', 'department', 'job_status', 'joining_date',
            'identification_number', 'blood_group', 'place_of_birth',
            'emergency_contact', 'emergency_phone', 'marital_status',
            'no_of_children', 'address', 'date_of_birth', 'gender',
            'city', 'state', 'postal_code', 'country', 'ssn', 'image',
        ]);
        $employeeData['business_id'] = $businessId;

        $createAccount = $request->boolean('create_account', false);
        $userData = null;
        $plainPassword = null;

        if ($createAccount) {
            $plainPassword = bin2hex(random_bytes(4));

            $userData = [
                'name' => trim($request->first_name . ' ' . $request->last_name) ?: $request->email,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
                'plain_password' => $plainPassword,
                'user_type' => auth()->user()->user_type ?? '',
            ];
        }

        $result = $this->employeeService->create($employeeData, $userData, $request->role_id ?? null);

        if (!empty($result['user'])) {
            $this->sendAccountCreatedMail($result['user'], $businessInfo, $plainPassword);
        }

        return response()->json([
            'status' => true,
            'message' => __('employee.employee_created'),
            'data' => [
                'employee' => $result['employee'],
                'user' => $result['user']?->makeHidden(['password']),
            ],
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateEmployeeRequest $request, int $id)
    {
        $employee = Employee::findOrFail($id);
        $businessId = $request->user()->business_id;

        if ($employee->business_id !== $businessId) {
            return response()->json([
                'status' => false,
                'message' => __('employee.unauthorized'),
            ], Response::HTTP_FORBIDDEN);
        }

        $employeeData = $request->only([
            'first_name', 'last_name', 'email', 'phone_number',
            'designation', 'department', 'job_status', 'joining_date',
            'identification_number', 'blood_group', 'place_of_birth',
            'emergency_contact', 'address', 'emergency_phone', 'marital_status',
            'no_of_children', 'date_of_birth', 'gender', 'city', 'state',
            'postal_code', 'country', 'ssn', 'image',
        ]);

        if ($request->hasFile('image')) {
            $employeeData['image'] = $request->file('image');
        }

        $createAccount = $request->boolean('create_account', false);
        $roleId = $request->role_id ?? null;

        $result = $this->employeeService->updateWithUserSync($employee, $employeeData, $createAccount, $roleId, $request->user()->business);

        return response()->json([
            'status' => true,
            'message' => __('employee.employee_updated'),
            'data' => [
                'employee' => $result['employee'],
                'user' => $result['user']?->makeHidden(['password']),
            ],
        ]);
    }

    public function destroy(Request $request, Employee $employee)
    {
        $businessId = $request->user()->business_id;

        if ($employee->business_id !== $businessId) {
            return response()->json([
                'status' => false,
                'message' => __('employee.unauthorized'),
            ], Response::HTTP_FORBIDDEN);
        }

        $this->employeeService->delete($employee, $request->boolean('delete_user', true));

        return response()->json([
            'status' => true,
            'message' => __('employee.employee_deleted'),
        ]);
    }

    public function sendAccountCreatedMail($user, $businessInfo, string $plainPassword): void
    {
        $data = [
            'subject' => 'Welcome to ' . $businessInfo->business_name,
            'user' => $user,
            'plain_password' => $plainPassword,
            'business_info' => [
                'business_name' => $businessInfo->business_name,
                'business_logo' => asset($businessInfo->business_logo),
                'business_email' => $businessInfo->business_email,
                'business_phone' => $businessInfo->business_phone,
                'address_line_1' => $businessInfo->address_line_1,
            ],
        ];

        try {
            Mail::to($user->email)->send(new AccountCreateNotificationMail($data));
        } catch (Throwable $e) {
            Log::error('📧 AccountCreatedNotificationMail failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
