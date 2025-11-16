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
    public function list(int $partnerId, array $filters)
    {
        $query = Employee::where('partner_id', $partnerId)
            ->with(['user']);

        // Global search using model scope
        if (!empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        // Quick equals filters
        if (!empty($filters['job_status'])) {
            $query->where('job_status', $filters['job_status']);
        }
        if (!empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        // Column filters (PrimeReact style or simple):
        // - PrimeReact: { field: { operator: 'and'|'or', constraints: [{ value, matchMode }] } }
        // - Simple: { field: { value } }
        $rawFilters = $filters['filters'] ?? ($filters['filter'] ?? null);
        if (is_string($rawFilters)) {
            $decoded = json_decode($rawFilters, true);
            $rawFilters = is_array($decoded) ? $decoded : [];
        }

        if (is_array($rawFilters) && !empty($rawFilters)) {
            $hasConstraints = false;
            foreach ($rawFilters as $def) {
                if (is_array($def) && array_key_exists('constraints', $def)) {
                    $hasConstraints = true;
                    break;
                }
            }

            if ($hasConstraints) {
                foreach ($rawFilters as $field => $filterData) {
                    if (!isset($filterData['constraints']) || !is_array($filterData['constraints'])) {
                        continue;
                    }

                    $operator = strtolower($filterData['operator'] ?? 'and'); // and/or
                    $constraints = $filterData['constraints'];

                    $query->where(function ($q) use ($constraints, $field, $operator) {
                        foreach ($constraints as $index => $c) {
                            $value = $c['value'] ?? null;
                            $mode = $c['matchMode'] ?? null;
                            if ($value === null || $value === '') continue;

                            $method = ($operator === 'or' && $index > 0) ? 'orWhere' : 'where';

                            // Special field mapping
                            if ($field === 'full_name') {
                                $this->applyFullNameConstraint($q, $method, $mode, (string) $value);
                                continue;
                            }
                            $column = $field === 'phone_number' ? 'phone_number_1' : $field;

                            $this->applyConstraint($q, $column, $method, $mode, (string) $value);
                        }
                    });
                }
            } else {
                // Simple filters: { field: { value } }
                foreach ($rawFilters as $field => $def) {
                    if (!is_array($def) || empty($def['value'])) continue;
                    $value = trim((string) $def['value']);
                    if ($value === '') continue;

                    if ($field === 'full_name') {
                        $query->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$value}%"]);
                    } elseif ($field === 'phone_number') {
                        $query->where('phone_number_1', 'like', "%{$value}%");
                    } else {
                        $query->where($field, 'like', "%{$value}%");
                    }
                }
            }
        }

        // Sorting & pagination
       
        $sortField = $filters['sort_field']  ? ($filters['sort_field'] == 'full_name' ? 'first_name'  : $filters['sort_field']) : 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        
        $employees = $query->orderBy($sortField, $sortOrder)
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $employees->items(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'from' => $employees->firstItem(),
                'to' => $employees->lastItem(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
                'last_page' => $employees->lastPage(),
                'next_page_url' => $employees->nextPageUrl(),
                'prev_page_url' => $employees->previousPageUrl(),
                'links' => $employees->linkCollection(),
            ],
        ];

    }

    /**
     * Apply a single constraint for a given column and mode.
     */
    protected function applyConstraint($q, string $column, string $method, ?string $mode, string $value): void
    {
        $likeContains = "%{$value}%";
        $likeStarts = "{$value}%";
        $likeEnds = "%{$value}";

        switch ($mode) {
            case 'contains':
                $q->{$method}($column, 'like', $likeContains);
                break;
            case 'startsWith':
                $q->{$method}($column, 'like', $likeStarts);
                break;
            case 'endsWith':
                $q->{$method}($column, 'like', $likeEnds);
                break;
            case 'equals':
                $q->{$method}($column, '=', $value);
                break;
            case 'notEquals':
                $q->{$method}($column, '!=', $value);
                break;
            case 'in':
                $vals = is_array($value) ? $value : explode(',', $value);
                if ($method === 'orWhere') {
                    $q->orWhereIn($column, $vals);
                } else {
                    $q->whereIn($column, $vals);
                }
                break;
            case 'lt':
                $q->{$method}($column, '<', $value);
                break;
            case 'lte':
                $q->{$method}($column, '<=', $value);
                break;
            case 'gt':
                $q->{$method}($column, '>', $value);
                break;
            case 'gte':
                $q->{$method}($column, '>=', $value);
                break;
            default:
                // Fallback to contains
                $q->{$method}($column, 'like', $likeContains);
        }
    }

    /**
     * Apply constraint for full_name (CONCAT first and last).
     */
    protected function applyFullNameConstraint($q, string $method, ?string $mode, string $value): void
    {
        $likeContains = "%{$value}%";
        $likeStarts = "{$value}%";
        $likeEnds = "%{$value}";

        $callback = function ($builder) use ($mode, $likeContains, $likeStarts, $likeEnds, $value) {
            switch ($mode) {
                case 'contains':
                    $builder->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$likeContains]);
                    break;
                case 'startsWith':
                    $builder->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$likeStarts]);
                    break;
                case 'endsWith':
                    $builder->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$likeEnds]);
                    break;
                case 'equals':
                    $builder->whereRaw("CONCAT(first_name, ' ', last_name) = ?", [$value]);
                    break;
                case 'notEquals':
                    $builder->whereRaw("CONCAT(first_name, ' ', last_name) != ?", [$value]);
                    break;
                default:
                    $builder->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$likeContains]);
            }
        };

        if ($method === 'orWhere') {
            $q->orWhere(function ($builder) use ($callback) { $callback($builder); });
        } else {
            $q->where(function ($builder) use ($callback) { $callback($builder); });
        }
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
