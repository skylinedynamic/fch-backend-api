<?php

namespace App\Services\FCH\Common\Employee;

use Throwable;
use Carbon\Carbon;
use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Common\Role;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use App\Models\Invoice\Invoice;
use App\Models\Subscription\Subscription;
use App\Models\Business\Business;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\Common\Invoice\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Employee\EmployeeResource;
use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;

use App\Models\ActivityLog\ActivityLog;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Mail\AccountCreateNotificationMail;
use App\Services\FCH\Subscription\SubscriptionCreationService;
use Illuminate\Support\Facades\Mail;

class EmployeeService
{
    use PaginatesApi, UploadsFiles;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected ActivityLogService $activityLogService,
        protected SubscriptionCreationService $subscriptionCreationService,
    ) {
    }

    public function getAll()
    {
        $employees = Employee::query()->where('partner_id', Auth::user()->partner_id)->with(['user'])->orderBy('id', 'desc')->get();

        return ApiResponse::success(EmployeeResource::collection($employees), __('employee.success.retrieved'));
    }

    /**
     * Paginate employees for a business.
     */
    public function list(int $partnerId, array $filters)
    {
        $query = Employee::query()->where('partner_id', $partnerId)->with(['user']);

        if (!empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        if (!empty($filters['job_status'])) {
            $query->where('job_status', $filters['job_status']);
        }

        if (!empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ? ($filters['sort_field'] == 'full_name' ? 'first_name' : $filters['sort_field']) : 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'desc' : 'asc';

        if ($sortField === 'phone_number') {
            $sortField = 'phone_number_1';
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'phone_number' => 'phone_number_1',
                ],
                'concat_fields' => [
                    'full_name' => ['first_name', 'last_name'],
                ],
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            EmployeeResource::class,
            __('employee.employee_listed')
        );
    }

    /**
     * Create a new employee and optionally a linked user.
     */
    public function create(array $employeeData, ?array $userData = null, ?int $roleId = null): array
    {
        $partnerId = auth()->user()->partner_id;

        // A host partner is never billable, regardless of what the request sends —
        // computed once here and used everywhere below instead of re-reading
        // $employeeData['is_paid'] raw at each usage site.
        $partner = Partner::find($partnerId);
        $isPaid = ($partner && !$partner->is_host)
            ? (isset($employeeData['is_paid']) && (bool) $employeeData['is_paid'])
            : false;

        // Check user limit if not host and creating a user account and not purchasing a paid seat
        if ($partner && !$partner->is_host && $userData && !$isPaid) {
            $currentCount = User::where('partner_id', $partnerId)
                ->where(function ($query) {
                    $query->whereIn('entity_type', [Partner::class, Employee::class])
                        ->orWhereNull('entity_type');
                })
                ->count();

            $partnerSub = $partner->subscription;
            $allowedSeats = $partnerSub ? $partnerSub->user_seats : 3;

            if ($currentCount >= $allowedSeats) {
                throw new \Exception('User limit reached. Please purchase additional seats.');
            }
        }

        DB::beginTransaction();

        try {

            // 2️⃣ Create employee
            $employeeData['partner_id'] = $partner->id;

            // Format dates if present
            if (isset($employeeData['joining_date']) && !empty($employeeData['joining_date'])) {
                $employeeData['joining_date'] = Carbon::parse($employeeData['joining_date'])->format('Y-m-d');
            }

            if (isset($employeeData['date_of_birth']) && !empty($employeeData['date_of_birth'])) {
                $employeeData['date_of_birth'] = Carbon::parse($employeeData['date_of_birth'])->format('Y-m-d');
            }

            // Set default job_status if not provided
            if (!isset($employeeData['job_status']) || empty($employeeData['job_status'])) {
                $employeeData['job_status'] = 'Active';
            }
            $employeeData['employee_code'] = Employee::generateCode($partner->id);
            // $employeeData = collect($employeeData)->except(['months', 'is_paid', 'amount'])->toArray();
            $employee = Employee::create($employeeData);
            Log::info('Employee created successfully', ['employee_id' => $employee->id]);
            $user = null;

            $plainPassword = $userData['plain_password'] ?? null;
            // 1️⃣ Create user if needed
            if ($userData) {
                $user = User::create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'partner_id' => $partner->id,
                    'entity_type' => Employee::class,
                    'entity_id' => $employee->id,
                    'phone_number' => $employeeData['phone_number_1'] ?? null,
                    'password' => Hash::make($plainPassword),
                    'user_type' => auth()->user()->user_type ?? '',
                    'is_paid' => $isPaid,
                    'status' => $isPaid ? User::STATUS['inactive'] : User::STATUS['active'],
                ]);

                Log::info('User created successfully', ['user_id' => $user->id]);

                if ($roleId) {
                    $this->assignRoleAndPermissions($user, $roleId);
                }
            }

            if ($isPaid) {
                $partner = Partner::find($partnerId);
                $partnerSub = $partner ? $partner->subscription : null;
                $perSeatAmount = $partnerSub ? $partnerSub->per_seat_amount : 49.00;
                $months = (int) ($employeeData['months'] ?? 1);
                $amount = $perSeatAmount * $months;

                $subscription = $this->subscriptionCreationService->createPendingUserSeatSubscription(
                    Employee::class,
                    $employee->id,
                    $partner,
                    $perSeatAmount
                );

                $invoiceId = $this->invoiceService->generateInvoiceId();
                $invoice = Invoice::create([
                    'invoice_id' => $invoiceId,
                    'partner_id' => $partner->id,
                    'subscription_id' => $subscription->id,
                    'entity_type' => Partner::class,
                    'entity_id' => $partner->id,
                    'customer_name' => $partner ? ($partner->company_name ?: $partner->full_name) : ($employee->first_name . ' ' . $employee->last_name),
                    'email' => $partner ? $partner->email : $employee->email,
                    'payment_amount' => $amount,
                    'payment_date' => null,
                    'month_count' => $months,
                    'type' => Invoice::INVOICE_TYPE_USER_SEAT,
                    'status' => Invoice::STATUS_UNPAID,
                    'created_by' => Auth::id(),
                ]);
                $user->invoice_id = $invoice->id;
                $subscription->update(['current_cycle_invoice_id' => $invoice->id]);

                // Log Invoice Creation
                $this->activityLogService->logModelEvent(
                    $invoice,
                    ActivityLog::LOG_TYPE_INVOICE_CREATE
                );
            }

            if (isset($employeeData['image'])) {
                $imageName = ltrim($employeeData['image'], '/');
                $employeeData['image'] = 'employees/' . $employee->id . '/' . $imageName;
                $employee->update(['image' => $employeeData['image']]);
            }

            // Log System Event
            $this->activityLogService->logModelEvent(
                $employee,
                ActivityLog::LOG_TYPE_EMPLOYEE_CREATE,
                ['user_id' => $user?->id]
            );

            DB::commit();
            Log::info('Employee created successfully', ['employee_id' => $employee->id]);

            // 📧 Send welcome mail if user account was created
            if ($user) {
                $this->sendAccountCreatedMail($user, $partner, $plainPassword);
            }

            return [
                'employee' => $employee->fresh(),
                'user' => $user,
                'plain_password' => $plainPassword,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Employee creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update an employee and manage the linked user account.
     */
    public function updateWithUserSync(Employee $employee, array $employeeData, bool $createAccount, ?int $roleId, $partner): array
    {
        DB::beginTransaction();
        try {
            $user = $employee->user;
            $plainPassword = null;

            // Format dates if present
            if (isset($employeeData['joining_date']) && !empty($employeeData['joining_date'])) {
                $employeeData['joining_date'] = Carbon::parse($employeeData['joining_date'])->format('Y-m-d');
            }

            if (isset($employeeData['date_of_birth']) && !empty($employeeData['date_of_birth'])) {
                $employeeData['date_of_birth'] = Carbon::parse($employeeData['date_of_birth'])->format('Y-m-d');
            }

            // Set default job_status if not provided
            if (!isset($employeeData['job_status']) || empty($employeeData['job_status'])) {
                $employeeData['job_status'] = 'Active';
            }

            // 1️⃣ Handle image upload
            if (isset($employeeData['image'])) {

                $imageName = ltrim($employeeData['image'], '/');

                $employeeData['image'] = 'employees/' . $employee->id . '/' . $imageName;
            }

            // 2️⃣ Update employee data
            $employee->fill($employeeData);
            $isEmployeeDirty = $employee->isDirty([
                'first_name',
                'last_name',
                'email',
                'phone_number',
            ]);

            // 3️⃣ Handle user creation if needed
            if ($createAccount && !$user) {
                $plainPassword = bin2hex(random_bytes(4));

                $user = User::create([
                    'name' => trim($employee->first_name . ' ' . $employee->last_name),
                    'email' => $employee->email,
                    'phone_number' => $employee->phone_number_1,
                    'password' => Hash::make($plainPassword),
                    'user_type' => auth()->user()->user_type ?? '',
                    'entity_type' => Employee::class,
                    'entity_id' => $employee->id,
                    'partner_id' => $employee->partner_id,
                ]);

                Log::info('New user created for employee', ['employee_id' => $employee->id]);

                // Send welcome mail only for new user
                $this->sendAccountCreatedMail($user, $partner, $plainPassword);
            }

            // 4️⃣ Sync existing user if employee changed
            if ($user && $isEmployeeDirty) {
                $emailChanged = isset($employeeData['email']) && $user->email !== $employeeData['email'];
                $plainPassword = null;

                $updateData = [
                    'name' => trim($employee->first_name . ' ' . $employee->last_name),
                    'email' => $employee->email,
                    'phone_number' => $employee->phone_number_1 ?? $employee->phone_number,
                ];

                if ($emailChanged) {
                    $plainPassword = bin2hex(random_bytes(4));
                    $updateData['password'] = Hash::make($plainPassword);
                    Log::info('🔑 Password reset for Employee user due to email change', ['user_id' => $user->id]);
                }

                $user->update($updateData);

                Log::info('Existing user synced with employee changes', [
                    'employee_id' => $employee->id,
                    'email_changed' => $emailChanged,
                ]);

                // 📧 Send update notification if email was changed
                if ($emailChanged && $plainPassword) {
                    $this->sendAccountCreatedMail($user, $partner, $plainPassword, true);
                }
            }

            // 5️⃣ Assign or update role
            if ($roleId && $user) {
                $this->assignRoleAndPermissions($user, $roleId);
            }

            // 6️⃣ Save employee
            $oldJobStatus = $employee->getOriginal('job_status');
            $employee->save();

            // Sync user login status if employee job status changed to restricted state
            if ($employee->job_status !== $oldJobStatus && in_array($employee->job_status, [Employee::STATUS_SUSPENDED, Employee::STATUS_INACTIVE])) {
                $this->revokeEmployeeTokens($employee);
            }

            // Log System Event
            $this->activityLogService->logModelEvent(
                $employee,
                ActivityLog::LOG_TYPE_EMPLOYEE_UPDATE,
                ['user_id' => $user?->id]
            );

            DB::commit();
            Log::info('Employee updated successfully', ['employee_id' => $employee->id]);

            return [
                'employee' => $employee->fresh(),
                'user' => $user,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Employee update failed', [
                'employee_id' => $employee->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete an employee (and optionally linked user).
     */
    public function delete(Employee $employee, bool $deleteUser = false): bool
    {
        DB::beginTransaction();

        try {
            if ($employee->image) {
                $this->deleteFile($employee->getRawOriginal('image'), 'public');
                Log::info('Employee image deleted', ['employee_id' => $employee->id]);
            }

            $user = $employee->user;
            $employee->delete();
            Log::info('Employee record deleted', ['employee_id' => $employee->id]);

            if ($deleteUser && $user) {
                $user->delete();
                Log::info('Linked user deleted', ['user_id' => $user->id]);
            }

            // Log System Event
            $this->activityLogService->logModelEvent(
                $employee,
                ActivityLog::LOG_TYPE_EMPLOYEE_DELETE,
                ['user_id' => $user?->id, 'model_status' => 'deleted']
            );

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Employee deletion failed', [
                'employee_id' => $employee->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Assign role and its permissions to a user.
     */
    protected function assignRoleAndPermissions(User $user, int $roleId): void
    {
        $role = Role::where('partner_id', auth()->user()->partner_id)->where('id', $roleId)->first();
        // Use assignRole to ensure model_type is set correctly on the pivot
        $user->assignRole($role);

        $permissionIds = DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission_id');

        $permissions = Permission::whereIn('id', $permissionIds)->get();
        $user->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::info('User role and permissions synced', ['user_id' => $user->id, 'role_id' => $roleId]);
    }

    /**
     * Find employee by ID and business ID.
     */
    public function find(int $id): Employee
    {
        return Employee::where('id', $id)
            ->with('user')
            ->firstOrFail();
    }

    /**
     * Get employees filtered by department and job_status
     */
    public function getTypeData(int $partnerId, string $type): array
    {
        $query = Employee::query()->where('partner_id', $partnerId);

        if ($type === 'status') {
            // Get distinct job_status
            $result = $query->select('id', 'job_status as status')
                ->whereNotNull('job_status')
                ->get()
                ->unique('status') // remove duplicates by status
                ->values()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'status' => $item->status,
                    ];
                })
                ->toArray();
        } else { // department
            // Get distinct departments
            $result = $query->select('id', 'department')
                ->whereNotNull('department')
                ->get()
                ->unique('department') // remove duplicates by department
                ->values()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'department' => $item->department,
                    ];
                })
                ->toArray();
        }

        return $result;
    }

    /**
     * Delete Multiple
     */
    public function deleteMultiple(array $ids): bool
    {
        if (empty($ids)) {
            return false;
        }

        // Get employees to delete their images
        $employees = Employee::whereIn('id', $ids)->get();
        foreach ($employees as $employee) {
            if ($employee->image) {
                $this->deleteFile($employee->getRawOriginal('image'), 'public');
            }
        }

        // Delete employee records and associated users
        DB::transaction(function () use ($ids) {
            User::where('entity_type', Employee::class)
                ->where('partner_id', auth()->user()->partner_id)
                ->whereIn('entity_id', $ids)
                ->delete();

            Employee::where('partner_id', auth()->user()->partner_id)
                ->whereIn('id', $ids)
                ->delete();
        });

        return true;
    }

    /**
     * Get the next employee ID (last employee ID plus 1).
     */
    public function getNextEmployeeId(): int
    {
        return (Employee::max('id') ?? 0) + 1;
    }

    /**
     * Revoke all access tokens for the employee's user.
     */
    private function revokeEmployeeTokens(Employee $employee): void
    {
        $user = $employee->user;
        if ($user) {
            DB::table('oauth_access_tokens')
                ->where('user_id', $user->id)
                ->update(['revoked' => true]);

            Log::info("Force logged out employee user for Employee ID: {$employee->id} (User ID: {$user->id}) due to job status being set to {$employee->job_status}");
        }
    }

    /**
     * Send account created mail using partner's email config.
     */
    public function sendAccountCreatedMail($user, $businessInfo, string $plainPassword, bool $isUpdate = false): void
    {
        $data = [
            'partner_id' => $user->partner_id,
            'user' => $user,
            'plain_password' => $plainPassword,
            'is_update' => $isUpdate,
            'business_info' => [
                'company_name' => $businessInfo->company_name,
                'company_logo' => $businessInfo->company_logo,
                'email' => $businessInfo->email,
                'phone_number' => $businessInfo->phone_number,
                'address_line_1' => $businessInfo->address_line_1,
            ],
        ];

        try {
            Mail::to($user->email)->send(new AccountCreateNotificationMail($data));
        } catch (Throwable $e) {
            Log::error('📧 AccountCreatedNotificationMail failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
