<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Employee;

use Throwable;
use App\Traits\PaginatesApi;
use Illuminate\Http\Request;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Employee\FetchEmployee;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Resources\FCH\Employee\EmployeeResource;
use App\Services\FCH\Common\Employee\EmployeeService;
use App\Http\Requests\Api\V1\FCH\Common\Employee\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\FCH\Common\Employee\UpdateEmployeeRequest;
use App\Services\Global\Notification\Mail\AccountCreateNotificationMail;
use App\Helpers\ApiResponse;

class EmployeeController extends Controller
{
    use PaginatesApi;

    public function __construct(protected EmployeeService $employeeService)
    {
    }

    /**
     * Employee List
     */
    public function index(FetchEmployee $request)
    {
        try {
            $partnerId = $request->user()->partner_id;
            $input = $request->validated();

            // Sanitize and cap pagination to avoid heavy payloads
            $perPage = isset($input['per_page']) ? (int) $input['per_page'] : null;
            $perPage = $perPage !== null ? max(1, min($perPage, 100)) : null; // cap at 100

            $filters = [
                'per_page'   => $perPage,
                'page'       => isset($input['page']) ? max(1, (int) $input['page']) : 1,
                'search'     => isset($input['search']) ? trim((string) $input['search']) : null,
                'job_status' => $input['job_status'] ?? null,
                'department' => $input['department'] ?? null,
                'filters'    => $input['filters'] ?? null, // array expected per request rules
                'sort_field' => $input['sort_field'] ?? null,
                'sort_order' => $input['sort_order'] ?? null,
            ];

            $employees = $this->employeeService->list($partnerId, $filters);

            return ApiResponse::success([
                'employees' => EmployeeResource::collection($employees['data']),
                'meta'      => $employees['meta'],
            ], __('employee.success.retrieved'));
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => __('employee.employee_list_failed'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store Employee
     */
    public function store(StoreEmployeeRequest $request)
    {
        $businessId = $request->user()->business_id;
        $partnerInfo = $request->user()->partner;

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
            $this->sendAccountCreatedMail($result['user'], $partnerInfo, $plainPassword);
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

    /**
     * Update Employee
     */
    public function update(UpdateEmployeeRequest $request, int $id)
    {
        $employee = Employee::findOrFail($id);
        $businessId = $request->user()->partner_id;

        if ($employee->partner_id !== $businessId) {
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

    /**
     * Delete Employee
     */
    public function destroy(Request $request, Employee $employee)
    {
        $businessId = $request->user()->partnet_id;

        if ($employee->partnet_id !== $businessId) {
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
            'subject' => 'Welcome to ' . $businessInfo->company_name,
            'user' => $user,
            'plain_password' => $plainPassword,
            'business_info' => [
                'company_name' => $businessInfo->company_name,
                // 'business_logo' => asset($businessInfo->business_logo),
                'company_email' => $businessInfo->company_email,
                'company_phone_number' => $businessInfo->company_phone_number,
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
