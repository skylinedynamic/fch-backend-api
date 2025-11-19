<?php

namespace App\Services\FCH\Common\User;

use App\Helpers\ApiResponse;
use Throwable;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Traits\PaginatesApi;
use App\Models\Partner\Partner;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use App\Services\Global\Datatable\QueryFilter;
use Illuminate\Http\Request; // ✅ this is the correct one
use App\Services\Global\Notification\Mail\UserCreateNotificationMail;
use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;
use App\Http\Resources\Global\Auth\UserResource;
use Illuminate\Support\Facades\Auth;

class UserService
{
    /**
     * Create a new user and optionally link to employee
     */

     use PaginatesApi;
     
     /**
     * Create User
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
                'user_type' => auth()->user()->user_type ?? null,
                'password' => Hash::make($plainPassword),
                'partner_id' => auth()->user()->partner_id,
                'sattus' => User::STATUS['active'],
                'entity_type' => NULL,
                'entity_id' => NULL,
            ]);
            $partnerInfo = Partner::find(auth()->user()->partner_id);
            $this->sendCreatedMail($user, $partnerInfo, $plainPassword);

            // Assign role & permissions
            if ($roleId) {
                $this->assignRoleAndPermissions($user, $roleId);
            }

            DB::commit();
            return $user;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get List
     */
    // public function list(Request $request)
    // {
    //     $partnerId = auth()->user()->partner_id;

    //     $query = User::with('roles')
    //         ->where('partner_id', $partnerId);

    //     if ($request->filled('search')) {
    //         $search = $request->input('search');
    //         $query->where(function ($q) use ($search) {
    //             $q->where('name', 'like', "%{$search}%")
    //             ->orWhere('email', 'like', "%{$search}%")
    //             ->orWhere('phone_number', 'like', "%{$search}%")
    //             ->orWhereHas('roles', function ($roleQuery) use ($search) {
    //                 $roleQuery->where('name', 'like', "%{$search}%");
    //             });
    //         });
    //     }

    //     if ($request->filled('role_id')) {
    //         $roleId = $request->input('role_id');
    //         $query->whereHas('roles', function ($q) use ($roleId) {
    //             $q->where('id', $roleId);
    //         });
    //     }

    //     if ($request->filled('filters')) {
    //         $filtersInput = $request->input('filters');
    //         $filters = is_array($filtersInput) ? $filtersInput : json_decode($filtersInput, true);

    //         if (!empty($filters['email']['value'])) {
    //             $email = $filters['email']['value'];
    //             $query->where('email', 'like', "%{$email}%");
    //         }

    //         if (!empty($filters['status']['value'])) {
    //             $status = $filters['status']['value'];
    //             $query->where('status', $status);
    //         }
    //     }

    //     $allowedSortFields = ['id', 'name', 'email', 'status'];
    //     $sortField = $request->input('sort_field', 'id');
    //     $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

    //     if (!in_array($sortField, $allowedSortFields)) {
    //         $sortField = 'id';
    //     }

    //     $query->orderBy($sortField, $sortOrder);

    //     $perPage = $request->input('per_page')
    //         ? (int) $request->input('per_page')
    //         : config('pagination.per_page', 10);

    //     $page = $request->input('page', 1);

    //     return $query->paginate($perPage, ['*'], 'page', $page);
    // }

    /**
     * Paginate Users
     */

    public function getAll()
    {
         $users = User::query()->where('partner_id', Auth::user()->partner_id)->with(['roles'])->orderBy('id', 'desc')->get();
         return ApiResponse::success(UserResource::collection($users),__('user.user_list_retrieved'));

    }

    public function list(int $partnerId, array $filters)
    {
        $query = User::query()->where('partner_id', $partnerId)->with(['roles']);

        if (!empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['role_id'])) {
            $roleId = $filters['role_id'];
            $query->whereHas('roles', function ($q) use ($roleId) {
                $q->where('id', $roleId);
            });
        }

        //Sorting
        $sortField = $filters['sort_field']  ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'desc' ? 'desc' : 'asc';

       if( $filters['sort_field'] === 'role_name' ){
             $query = User::query()
            ->select('users.*')
            ->leftJoin('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->orderBy('roles.name', $filters['sort_order'])
            ->with('roles');
        }
        else {
            $query = QueryFilter::apply($query,$sortField,$sortOrder, $filters['filters']);
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

    
        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            UserResource::class,
            __('user.user_list_retrieved')
        );
    }

    /**
     * Update User
     */
    public function update(User $user, array $data, ?int $roleId = null): User
    {
        DB::beginTransaction();

        try {
            // 1️⃣ Hash password if provided
            if (!empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user->update($data);

            if ($roleId) {
                $this->assignRoleAndPermissions($user, $roleId);
            }

            DB::commit();
            return $user;

        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Assign Role
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

    /**
     * Delete User
     */
     public function delete(User $user): bool
    {
        DB::beginTransaction();

        try {

            $user->delete();
            DB::commit();
            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function sendCreatedMail($user, $businessInfo, string $plainPassword): void
    {
        $data = [
            'subject' => 'Welcome to ' . $businessInfo->company_name,
            'user' => $user,
            'plain_password' => $plainPassword,
            'business_info' => [
                'company_name' => $businessInfo->company_name,
                'company_email' => $businessInfo->company_email,
                'company_phone_number' => $businessInfo->company_phone_number,
                'address_line_1' => $businessInfo->address_line_1,
            ],
        ];

        try {
            Mail::to($user->email)->send(new UserCreateNotificationMail($data));
        } catch (Throwable $e) {
            Log::error('📧 UserCreateNotificationMail failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
