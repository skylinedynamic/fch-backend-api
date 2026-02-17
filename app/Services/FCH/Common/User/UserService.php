<?php

namespace App\Services\FCH\Common\User;

use Throwable;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\Global\Auth\UserResource;
use Illuminate\Http\Request; // ✅ this is the correct one
use App\Services\Global\Notification\Mail\UserCreateNotificationMail;
use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;

class UserService
{
    /**
     * Create a new user and optionally link to employee
     */

    use PaginatesApi;

    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

    /**
     * Create User
     */
    public function create(array $data, ?int $roleId = null): User
    {
        $authUser = auth()->user();
        $plainPassword = $data['password'] ?? bin2hex(random_bytes(4));

        DB::beginTransaction();

        try {
            $user = User::create([
                'name'         => $data['name'],
                'email'        => $data['email'],
                'user_id'      => $data['user_id'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'user_type'    => $authUser->user_type,
                'password'     => Hash::make($plainPassword),
                'partner_id'   => $authUser->partner_id,
                'status'       => isset($data['is_paid']) && $data['is_paid'] ? User::STATUS['inactive'] : User::STATUS['active'],
                'entity_type'  => null,
                'entity_id'    => null,
                'is_paid'      => isset($data['is_paid']) ? $data['is_paid'] : false,
                'payment_status' => isset($data['is_paid']) && $data['is_paid'] ? User::PAYMENT_STATUS_UNPAID : null,
                'start_date'   => isset($data['is_paid']) && $data['is_paid'] ? now() : null,
                'end_date'     => isset($data['is_paid']) && $data['is_paid'] ? now()->addMonths($data['months']) : null,
            ]);

            $roleId = $data['role_id'] ?? null;

            if ($roleId) {
                $this->assignRoleAndPermissions($user, $roleId);
            }
            if (isset($data['is_paid']) && $data['is_paid']) {
                $invoiceId = $this->invoiceService->generateInvoiceId();
                $invoice = Invoice::create([
                    'invoice_id' => $invoiceId,
                    'partner_id' => $authUser->partner_id,
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'customer_name' => $user->name,
                    'email' => $user->email,
                    'payment_amount' => $data['amount'],
                    'payment_date' => null,
                    'status' => Invoice::STATUS_UNPAID,
                    'created_by' => Auth::id(),
                ]);
                $user->invoice_id = $invoice->id;
            } else {
                $user->invoice_id = null;
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e; // user not created if DB fails
        }

        // AFTER COMMIT — email section
        // try {
        //     $userInfo = User::find($user->id);
        //     $this->sendCreatedMail($userInfo, $userInfo, $plainPassword);
        // } catch (Throwable $e) {
        //     Log::error('User created but email failed', [
        //         'user_id' => $user->id,
        //         'error'   => $e->getMessage(),
        //     ]);
        // }

        return $user;
    }


    /**
     * Get All
     */

    public function getAll()
    {
        $query = User::query()->where('partner_id', Auth::user()->partner_id)->with(['roles', 'entity'])->orderBy('id', 'desc');

        if (!Auth::user()->hasRole('Developer')) {
            $query->whereDoesntHave('roles', function ($q) {
                $q->where('code', 'developer');
            });
        }

        $users = $query->get();
        return ApiResponse::success(UserResource::collection($users), __('user.user_list_retrieved'));
    }

    /**
     * Get List
     */
    public function list(int $partnerId, array $filters)
    {
        $query = User::query()
            ->where('partner_id', $partnerId)
            ->where(function ($query) {
                $query->whereNull('entity_type')
                    ->orWhere('entity_type', Partner::class);
            })
            ->with([
                'roles',
                'partner',
                'entity' => function ($morphTo) {
                    $morphTo->morphWith([
                        Borrower::class => ['subscription.enrollmentPhase', 'subscription.platformFeePhase'],
                        Partner::class  => ['subscription'],
                    ]);
                }
            ]);

        if (!Auth::user()->hasRole('Developer')) {
            $query->whereDoesntHave('roles', function ($q) {
                $q->where('code', 'developer');
            });
        }
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

        // Handle custom filters manually
        $rawFilters = $filters['filters'] ?? [];
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true) ?? [];
        }

        // 1. Filter by Role Name
        if (isset($rawFilters['role_name'])) {
            $roleConstraints = $rawFilters['role_name']['constraints'] ?? [];
            foreach ($roleConstraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('roles', function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['role_name']); // Remove so QueryFilter doesn't process it
        }

        // 2. Filter by Entity Type
        if (isset($rawFilters['entity_type'])) {
            $entityConstraints = $rawFilters['entity_type']['constraints'] ?? [];
            foreach ($entityConstraints as $constraint) {
                $value = $constraint['value'] ?? null;

                // Map frontend values to backend class names
                $map = [
                    'Employee' => Employee::class,
                    'Partner'  => Partner::class,
                    'Borrower' => \App\Models\Borrower\Borrower::class,
                    'Admin'    => null, // Admin usually has no entity_type
                ];

                if (array_key_exists($value, $map)) {
                    $mappedValue = $map[$value];
                    if ($mappedValue === null) {
                        $query->whereNull('entity_type');
                    } else {
                        $query->where('entity_type', $mappedValue);
                    }
                } elseif ($value) {
                    // Fallback for direct search
                    $query->where('entity_type', 'like', "%{$value}%");
                }
            }
            unset($rawFilters['entity_type']); // Remove so QueryFilter doesn't process it
        }

        if ($filters['sort_field'] === 'role_name') {
            $query->withMin('roles', 'name')
                ->orderBy('roles_min_name', $sortOrder);
        } else {
            $query = QueryFilter::apply($query, $sortField, $sortOrder, $rawFilters);
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
        if ($user->hasRole('Developer') && !Auth::user()->hasRole('Developer')) {
            throw new \Exception(__('user.not_authorized_to_update_developer'));
        }

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
        $role = Role::where('partner_id', auth()->user()->partner_id)->findOrFail($roleId);

        // Use assignRole to ensure model_type is populated correctly in polymorphic pivot
        $user->assignRole($role);

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
                'email' => $businessInfo->email,
                'phone_number' => $businessInfo->phone_number,
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
