<?php

namespace App\Services\FCH\Common\Employee;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;
use App\Http\Resources\FCH\Employee\EmployeeResource;
use App\Models\Business\Business;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\User\User;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class EmployeeService
{
    use PaginatesApi, UploadsFiles;

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

        if (! empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        if (! empty($filters['job_status'])) {
            $query->where('job_status', $filters['job_status']);
        }

        if (! empty($filters['department'])) {
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
        DB::beginTransaction();

        try {

            // 2️⃣ Create employee
            $employeeData['partner_id'] = auth()->user()->partner_id;

            // Format dates if present
            if (isset($employeeData['joining_date']) && ! empty($employeeData['joining_date'])) {
                $employeeData['joining_date'] = Carbon::parse($employeeData['joining_date'])->format('Y-m-d');
            }

            if (isset($employeeData['date_of_birth']) && ! empty($employeeData['date_of_birth'])) {
                $employeeData['date_of_birth'] = Carbon::parse($employeeData['date_of_birth'])->format('Y-m-d');
            }

            // Set default job_status if not provided
            if (! isset($employeeData['job_status']) || empty($employeeData['job_status'])) {
                $employeeData['job_status'] = 'Active';
            }

            $employeeData['employee_code'] = Employee::generateCode(auth()->user()->partner_id);

            $employee = Employee::create($employeeData);
            Log::info('✅ Employee created successfully', ['employee_id' => $employee->id]);
            $user = null;

            $plainPassword = $userData['plain_password'] ?? null;

            // 1️⃣ Create user if needed
            if ($userData) {
                $user = User::create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'partner_id' => auth()->user()->partner_id,
                    'entity_type' => Employee::class,
                    'entity_id' => $employee->id,
                    'phone_number' => $employeeData['phone_number_1'] ?? null,
                    'password' => Hash::make($plainPassword),
                    'user_type' => auth()->user()->user_type ?? '',
                ]);

                Log::info('✅ User created successfully', ['user_id' => $user->id]);

                if ($roleId) {
                    $this->assignRoleAndPermissions($user, $roleId);
                }
            }

            // 3️⃣ Upload image if provided
            if (isset($employeeData['image']) && $employeeData['image'] instanceof UploadedFile) {
                $employee->update(['image' => $employeeData['image']]);
                Log::info('🖼️ Employee image uploaded', ['path' => $upload['path']]);
            }

            DB::commit();

            return [
                'employee' => $employee->fresh(),
                'user' => $user,
                'plain_password' => $plainPassword,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Employee creation failed', ['error' => $e->getMessage()]);
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
            if (isset($employeeData['joining_date']) && ! empty($employeeData['joining_date'])) {
                $employeeData['joining_date'] = Carbon::parse($employeeData['joining_date'])->format('Y-m-d');
            }

            if (isset($employeeData['date_of_birth']) && ! empty($employeeData['date_of_birth'])) {
                $employeeData['date_of_birth'] = Carbon::parse($employeeData['date_of_birth'])->format('Y-m-d');
            }

            // Set default job_status if not provided
            if (! isset($employeeData['job_status']) || empty($employeeData['job_status'])) {
                $employeeData['job_status'] = 'Active';
            }

            // 1️⃣ Handle image upload
            if (isset($employeeData['image']) && $employeeData['image'] instanceof UploadedFile) {
                // if ($employee->image) {
                //     $this->deleteFile($employee->getRawOriginal('image'), 'public');
                // }
                // $upload = $this->uploadFile($employeeData['image'], "employees/{$employee->id}", 'public');
                $employeeData['image'] = $employeeData['image'];
            }

            // 2️⃣ Update employee data
            $employee->fill($employeeData);
            $isEmployeeDirty = $employee->isDirty([
                'first_name', 'last_name', 'email', 'phone_number',
            ]);

            // 3️⃣ Handle user creation if needed
            if ($createAccount && ! $user) {
                $plainPassword = bin2hex(random_bytes(4));

                $user = User::create([
                    'name' => trim($employee->first_name.' '.$employee->last_name),
                    'email' => $employee->email,
                    'phone_number' => $employee->phone_number_1,
                    'password' => Hash::make($plainPassword),
                    'user_type' => auth()->user()->user_type ?? '',
                    'entity_type' => Employee::class,
                    'entity_id' => $employee->id,
                    'partner_id' => $employee->partner_id,
                ]);

                Log::info('✅ New user created for employee', ['employee_id' => $employee->id]);

                // Send welcome mail only for new user
                app(EmployeeController::class)
                    ->sendAccountCreatedMail($user, $partner, $plainPassword);
            }

            // 4️⃣ Sync existing user if employee changed
            if ($user && $isEmployeeDirty) {
                $user->update([
                    'name' => trim($employee->first_name.' '.$employee->last_name),
                    'email' => $employee->email,
                    'phone_number' => $employee->phone_number,
                ]);
                Log::info('🔁 Existing user synced with employee changes', [
                    'employee_id' => $employee->id,
                ]);
            }

            // 5️⃣ Assign or update role
            if ($roleId && $user) {
                $this->assignRoleAndPermissions($user, $roleId);
            }

            // 6️⃣ Save employee
            $employee->save();

            DB::commit();
            Log::info('✅ Employee updated successfully', ['employee_id' => $employee->id]);

            return [
                'employee' => $employee->fresh(),
                'user' => $user,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Employee update failed', [
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
                Log::info('🗑️ Employee image deleted', ['employee_id' => $employee->id]);
            }

            $user = $employee->user;
            $employee->delete();
            Log::info('✅ Employee record deleted', ['employee_id' => $employee->id]);

            if ($deleteUser && $user) {
                $user->delete();
                Log::info('🗑️ Linked user deleted', ['user_id' => $user->id]);
            }

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Employee deletion failed', [
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
        Log::info('🔒 User role and permissions synced', ['user_id' => $user->id, 'role_id' => $roleId]);
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
}
