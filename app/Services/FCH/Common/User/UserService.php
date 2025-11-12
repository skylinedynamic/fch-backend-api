<?php

namespace App\Services\FCH\Common\User;

use App\Models\User\User;
use App\Models\Employee\Employee;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class UserService
{
    /**
     * Create a new user and optionally link to employee
     */
    public function create(array $data, ?int $roleId = null): User
    {
        DB::beginTransaction();
        try {
            $plainPassword = $data['password'] ?? bin2hex(random_bytes(4));

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'user_id' => $data['user_id'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'password' => Hash::make($plainPassword),
            ]);

            // Assign role & permissions
            if ($roleId) {
                $this->assignRoleAndPermissions($user, $roleId);
            }

            // Update linked employee if user_id provided
            if (!empty($data['employee_id'])) {
                $employee = Employee::find($data['employee_id']);
                if ($employee) {
                    $employee->user_id = $user->id;
                    $employee->save();
                }
            }

            DB::commit();
            return $user;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get list of users with optional filters
     */
    public function list(array $filters = [], int $perPage = 10)
    {
        // Get the authenticated user's business_id
        $businessId = auth()->user()->business_id;

        // Get all user_ids from the employee table that belong to this business
        $employeeUserIds = Employee::where('business_id', $businessId)->pluck('user_id');

        // Start the query for users whose IDs are in $employeeUserIds
        $query = User::with('employee')
            ->whereIn('id', $employeeUserIds);

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        // Filter by role if provided
        if (!empty($filters['role_id'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('id', $filters['role_id']);
            });
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Update user and optionally sync role & employee
     */
    public function update(User $user, array $data, ?int $roleId = null): User
    {
        DB::beginTransaction();

        try {
            // 1️⃣ Hash password if provided
            if (!empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            // 2️⃣ Keep original values for comparison
            $original = $user->only(['name', 'email', 'phone_number']);

            // 3️⃣ Update user
            $user->update($data);

            // 4️⃣ Update role & permissions if roleId provided
            if ($roleId) {
                $this->assignRoleAndPermissions($user, $roleId);
            }

            // 5️⃣ Sync linked employee if exists
            if ($employee = $user->employee) {
                $isDirty = false;

                foreach (['name', 'email', 'phone_number'] as $field) {
                    if (isset($data[$field]) && $original[$field] !== $data[$field]) {
                        $isDirty = true;
                        break;
                    }
                }

                if ($isDirty) {
                    // Split name into first_name / last_name
                    $nameParts = explode(' ', $data['name'] ?? $user->name, 2);

                    $employee->update([
                        'first_name'   => $nameParts[0] ?? null,
                        'last_name'    => $nameParts[1] ?? null,
                        'email'        => $data['email'] ?? $employee->email,
                        'phone_number' => $data['phone_number'] ?? $employee->phone_number,
                    ]);
                }
            }

            DB::commit();
            return $user;

        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Assign role & permissions to user
     */
    protected function assignRoleAndPermissions(User $user, int $roleId)
    {
        $role = Role::findOrFail($roleId);
        $user->syncRoles([$role->name]);

        $permissionIds = DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission_id');

        $permissions = Permission::whereIn('id', $permissionIds)->get();
        $user->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

     public function delete(User $user, bool $deleteEmployee = true): bool
    {
        DB::beginTransaction();

        try {

            $user->delete(); // Ensure User model uses SoftDeletes
            if ($deleteEmployee && $user->employee) {
                $user->employee->delete(); // Ensure Employee model uses SoftDeletes
            }

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
