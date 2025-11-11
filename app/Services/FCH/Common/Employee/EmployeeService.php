<?php

namespace App\Services\FCH\Common\Employee;

use Throwable;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Traits\UploadsFiles;
use App\Models\Business\Business;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;

class EmployeeService
{
    use UploadsFiles;

    /**
     * Paginate employees for a business.
     */
    public function list(int $businessId, int $perPage = 10, ?array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Employee::where('business_id', $businessId)
            ->with(['user', 'user.business']);

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('designation', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('job_status', $filters['status']);
        }

        if (!empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Create a new employee and optionally a linked user.
     */
    public function create(array $employeeData, ?array $userData = null, ?int $roleId = null): array
    {
        DB::beginTransaction();

        try {
            $user = null;
            $plainPassword = $userData['plain_password'] ?? null;

            // 1️⃣ Create user if needed
            if ($userData) {
                $user = User::create([
                    'name'         => $userData['name'],
                    'email'        => $userData['email'],
                    'phone_number' => $userData['phone_number'] ?? null,
                    'password'     => Hash::make($plainPassword),
                    'user_type'    => auth()->user()->user_type ?? '',
                ]);

                Log::info('✅ User created successfully', ['user_id' => $user->id]);

                if ($roleId) {
                    $this->assignRoleAndPermissions($user, $roleId);
                }

                $employeeData['user_id'] = $user->id;
            }

            // 2️⃣ Create employee
            $employee = Employee::create($employeeData);
            Log::info('✅ Employee created successfully', ['employee_id' => $employee->id]);

            // 3️⃣ Upload image if provided
            if (isset($employeeData['image']) && $employeeData['image'] instanceof UploadedFile) {
                $upload = $this->uploadFile($employeeData['image'], "employees/{$employee->id}", 'public');
                $employee->update(['image' => $upload['path']]);
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
    public function updateWithUserSync(Employee $employee, array $employeeData, bool $createAccount, ?int $roleId, ?Business $business): array
    {
        DB::beginTransaction();
        try {
            $user = $employee->user;
            $plainPassword = null;

            // 1️⃣ Handle image upload
            if (isset($employeeData['image']) && $employeeData['image'] instanceof UploadedFile) {
                if ($employee->image) {
                    $this->deleteFile($employee->image, 'public');
                }
                $upload = $this->uploadFile($employeeData['image'], "employees/{$employee->id}", 'public');
                $employeeData['image'] = $upload['path'];
            }

            // 2️⃣ Update employee data
            $employee->fill($employeeData);
            $isEmployeeDirty = $employee->isDirty([
                'first_name', 'last_name', 'email', 'phone_number'
            ]);

            // 3️⃣ Handle user creation if needed
            if ($createAccount && !$user) {
                $plainPassword = bin2hex(random_bytes(4));

                $user = User::create([
                    'name'         => trim($employee->first_name . ' ' . $employee->last_name),
                    'email'        => $employee->email,
                    'phone_number' => $employee->phone_number,
                    'password'     => Hash::make($plainPassword),
                    'user_type'    => 'employee',
                ]);

                $employee->user_id = $user->id;
                Log::info('✅ New user created for employee', ['employee_id' => $employee->id]);

                // Send welcome mail only for new user
                app(EmployeeController::class)
                    ->sendAccountCreatedMail($user, $business, $plainPassword);
            }

            // 4️⃣ Sync existing user if employee changed
            if ($user && $isEmployeeDirty) {
                $user->update([
                    'name'         => trim($employee->first_name . ' ' . $employee->last_name),
                    'email'        => $employee->email,
                    'phone_number' => $employee->phone_number,
                ]);
                Log::info('🔁 Existing user synced with employee changes', [
                    'user_id' => $user->id,
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
                $this->deleteFile($employee->image, 'public');
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
        $role = Role::findOrFail($roleId);
        $user->syncRoles([$role->name]);

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
    public function find(int $id, int $businessId): Employee
    {
        return Employee::where('id', $id)
            ->where('business_id', $businessId)
            ->with('user')
            ->firstOrFail();
    }
}
