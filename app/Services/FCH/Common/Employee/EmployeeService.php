<?php

namespace App\Services\FCH\Common\Employee;

use App\Models\Employee\Employee;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Traits\UploadsFiles; // ✅ FIXED HERE
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Http\UploadedFile;
use Exception;

class EmployeeService
{
    use UploadsFiles; // ✅ Must match the actual trait name

    /**
     * Paginate employee list for a given business.
     */
    public function list(int $businessId, int $perPage = 10, ?array $filters = [])
    {
        
        $query = Employee::where('business_id', $businessId)
            ->with(['user', 'user.business']);

        // 🔍 Search filter (first_name, last_name, full name, etc.)
     
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]) // ✅ full name
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone_number', 'like', "%{$search}%")
                ->orWhere('department', 'like', "%{$search}%")
                ->orWhere('designation', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%");
            });
        }

        // ✅ Filter by job status
        if (!empty($filters['status'])) {
            $query->where('job_status', $filters['status']);
        }

        // ✅ Filter by department
       
        if (!empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }
        
        return $query->orderBy('id', 'desc')->paginate($perPage);
    }

    /**
     * Create employee optionally with user account and role.
     */
    public function create(array $employeeData, ?array $userData = null, ?int $roleId = null)
    {
        return DB::transaction(function () use ($employeeData, $userData, $roleId) {
            $user = null;
            $plainPassword = $userData['plain_password'] ?? null;

            /** 1️⃣ Create user (if needed) */
            if ($userData) {
                $user = User::create([
                    'name'         => $userData['name'],
                    'email'        => $userData['email'],
                    'phone_number' => $userData['phone_number'] ?? null,
                    'password'     => Hash::make($plainPassword),
                    'user_type'    => auth()->user()->user_type ?? '',
                ]);

                // Assign role + permissions
                if ($roleId) {
                    $role = Role::findOrFail($roleId);
                    $user->assignRole($role);

                    $permissionIds = DB::table('role_has_permissions')
                        ->where('role_id', $roleId)
                        ->pluck('permission_id');

                    $permissions = Permission::whereIn('id', $permissionIds)->get();
                    $user->syncPermissions($permissions);

                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                }

                $employeeData['user_id'] = $user->id;
            }

            /** 2️⃣ Create employee */
            $employee = Employee::create($employeeData);

            /** 3️⃣ Upload image if provided */
            if (isset($employeeData['image']) && $employeeData['image'] instanceof UploadedFile) {
                $upload = $this->uploadFile(
                    $employeeData['image'],
                    "employees/{$employee->id}",
                    'public'
                );

                $employee->update(['image' => $upload['path']]);
            }

            return [
                'employee' => $employee,
                'user' => $user,
                'plain_password' => $plainPassword,
            ];
        });
    }

    /**
     * Update existing employee (and user account if exists)
     */
    public function update(Employee $employee, array $employeeData, ?array $userData = null, ?int $roleId = null)
    {
        return DB::transaction(function () use ($employee, $employeeData, $userData, $roleId) {
            $user = $employee->user;

            /** 1️⃣ Replace image (if new one uploaded) */
            if (isset($employeeData['image']) && $employeeData['image'] instanceof UploadedFile) {
                if ($employee->image) {
                    $this->deleteFile($employee->image, 'public');
                }

                $upload = $this->uploadFile(
                    $employeeData['image'],
                    "employees/{$employee->id}",
                    'public'
                );

                $employeeData['image'] = $upload['path'];
            }

            /** 2️⃣ Manage linked user account */
            if ($userData && !$user) {
                $user = User::create([
                    'name'         => $userData['name'] ?? ($employee->first_name . ' ' . $employee->last_name),
                    'email'        => $userData['email'] ?? null,
                    'phone_number' => $userData['phone_number'] ?? null,
                    'password'     => Hash::make($userData['plain_password'] ?? bin2hex(random_bytes(4))),
                    'user_type'    => $userData['user_type'] ?? 'employee',
                ]);
                $employee->user_id = $user->id;
            } elseif ($user && $userData) {
                $user->update([
                    'name'         => $userData['name'] ?? $user->name,
                    'email'        => $userData['email'] ?? $user->email,
                    'phone_number' => $userData['phone_number'] ?? $user->phone_number,
                    'password'     => isset($userData['password'])
                        ? Hash::make($userData['password'])
                        : $user->password,
                ]);
            }

            /** 3️⃣ Sync role + permissions */
            if ($roleId && $user) {
                $role = Role::findOrFail($roleId);
                $user->syncRoles([$role->name]);

                $permissionIds = DB::table('role_has_permissions')
                    ->where('role_id', $roleId)
                    ->pluck('permission_id');

                $permissions = Permission::whereIn('id', $permissionIds)->get();
                $user->syncPermissions($permissions);

                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            /** 4️⃣ Save employee */
            $employee->fill($employeeData)->save();
            $employee->refresh();

            return [
                'employee' => $employee,
                'user' => $user,
            ];
        });
    }

    /**
     * Delete employee (and optionally linked user)
     */
    public function delete(Employee $employee, bool $deleteUser = false)
    {
        return DB::transaction(function () use ($employee, $deleteUser) {
            if ($employee->image) {
                $this->deleteFile($employee->image, 'public');
            }

            $user = $employee->user;
            $employee->delete();

            if ($deleteUser && $user) {
                $user->delete();
            }

            return true;
        });
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
