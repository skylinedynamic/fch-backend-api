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
use App\Models\ActivityLog\ActivityLog;
use Spatie\Permission\PermissionRegistrar;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\Global\Auth\UserResource;
use App\Services\Global\Notification\Mail\UserCreateNotificationMail;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\Setting\EmailConfigurationService;

class UserService
{
    /**
     * Create a new user and optionally link to employee
     */

    use PaginatesApi;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected ActivityLogService $activityLogService,
        protected EmailConfigurationService $emailConfigService
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

            // Log Invoice Creation if present
            if ($user->invoice_id) {
                $createdInvoice = Invoice::find($user->invoice_id);
                if ($createdInvoice) {
                    $this->activityLogService->logModelEvent(
                        $createdInvoice,
                        ActivityLog::LOG_TYPE_INVOICE_CREATE
                    );
                }
            }

            // Log System Event
            $this->activityLogService->logModelEvent(
                $user,
                ActivityLog::LOG_TYPE_USER_CREATE
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e; // user not created if DB fails
        }

        if ($user && !$user->is_paid) {
            // AFTER COMMIT — email section
            try {
                $userInfo = User::find($user->id);
                $this->sendCreatedMail($userInfo, $userInfo, $plainPassword);
            } catch (Throwable $e) {
                Log::error('User created but email failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

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
                    ->orWhereIn('entity_type', [
                        Partner::class,
                        Employee::class,
                    ]);
            })
            ->with([
                'roles',
                'partner',
                'entity' => function ($morphTo) {
                    $morphTo->morphWith([
                        Borrower::class => ['subscription.enrollmentPhase', 'subscription.platformFeePhase'],
                        Partner::class  => ['subscription'],
                        Employee::class => [], // optional (add relations if needed)
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

        // 3. Filter by Start Date (Search in both start_date and end_date)
        if (isset($rawFilters['start_date'])) {
            $constraints = $rawFilters['start_date']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('start_date', 'like', "%{$value}%")
                            ->orWhere('end_date', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['start_date']);
        }

        // 4. Filter by End Date
        if (isset($rawFilters['end_date'])) {
            $constraints = $rawFilters['end_date']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->where('end_date', 'like', "%{$value}%");
                }
            }
            unset($rawFilters['end_date']);
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



        try {
            $emailChanged = isset($data['email']) && $user->email !== $data['email'];
            $passwordUpdated = false;
            $plainPassword = null;

            if (isset($data['password']) && $data['password'] !== null) {
                $plainPassword = $data['password'];
                $data['password'] = Hash::make($plainPassword);
                $passwordUpdated = true;
                Log::info('🔑 Password explicitly updated for User', ['user_id' => $user->id]);
            } elseif ($emailChanged) {
                $plainPassword = bin2hex(random_bytes(4));
                $data['password'] = Hash::make($plainPassword);
                $passwordUpdated = true;
                Log::info('🔑 Password reset for User due to email change', ['user_id' => $user->id]);
            }

            $oldStatus = $user->status;
            $user->update($data);

            // Sync user login status if user status changed to inactive
            if ($user->status !== $oldStatus && $user->status === User::STATUS['inactive']) {
                $this->revokeUserTokens($user);
            }

            if ($roleId) {
                $this->assignRoleAndPermissions($user, $roleId);
            }

            // Log System Event
            $this->activityLogService->logModelEvent(
                $user,
                ActivityLog::LOG_TYPE_USER_UPDATE
            );

            // 📧 Send update notification if password or email changed
            if ($passwordUpdated || $emailChanged) {
                $subject = ($emailChanged ? 'Your Email has been changed' : 'Your Password has been updated') . ' - Four Corner Funding';
                $partner = $user->partner ?: \App\Models\Partner\Partner::where('is_host', 1)->first();
                $this->sendCreatedMail($user, $partner, $plainPassword, $subject);
            }

            return $user;
        } catch (Throwable $e) {
            Log::error('UserService update failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Assign Role
     */
    protected function assignRoleAndPermissions(User $user, int $roleId)
    {
        try {
            $authUser = auth()->user();

            $role = Role::where('partner_id', $authUser->partner_id)->findOrFail($roleId);
            $user->syncRoles([$role->id]);

            $permissionIds = $role->permissions->pluck('id');
            $permissions = Permission::whereIn('id', $permissionIds)->get();
            $user->syncPermissions($permissions);

            $user->is_refresh = true;
            $user->save();

            $user->load('roles', 'permissions');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable $e) {

            throw $e;
        }
    }

    /**
     * Delete User
     */
    public function delete(User $user): bool
    {
        try {
            return $user->safeDeleteWithRelations();
        } catch (\Throwable $e) {
            Log::error('User deletion failed', ['id' => $user->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete Multiple Users
     */
    public function deleteMultiple(array $ids): bool
    {
        $users = User::whereIn('id', $ids)->get();

        foreach ($users as $user) {
            // Check if user is a developer and auth user is not
            if (!auth()->user()->hasRole('Developer') && $user->hasRole('Developer')) {
                continue;
            }
            $this->delete($user);
        }

        return true;
    }

    /**
     * Check if multiple users are deletable
     */
    public function checkDeletable(array $ids): array
    {
        $users = User::whereIn('id', $ids)->get();
        $results = [];

        foreach ($users as $user) {
            $is_deletable = $user->isDeletable();
            $results[] = [
                'id' => $user->id,
                'name' => $user->name,
                'is_deletable' => $is_deletable,
                'reason' => !$is_deletable ? "This record cannot be deleted. Please mark it as Suspended or Inactive instead." : null
            ];
        }

        return $results;
    }

    public function sendCreatedMail($user, $businessInfo, string $plainPassword, ?string $subject = null): void
    {
        $partnerId = $user->partner_id ?? null;
        $restore = $this->emailConfigService->applyToMailer($partnerId);

        try {
            $data = [
                'subject'        => $subject ?? ('Welcome to ' . config('app.name')),
                'user'           => $user,
                'plain_password' => $plainPassword,
                'partner_id'     => $partnerId,
            ];

            Mail::to($user->email)->send(new UserCreateNotificationMail($data));
        } catch (Throwable $e) {
            Log::error('📧 UserCreateNotificationMail failed', [
                'user_id' => $user->id ?? null,
                'error'   => $e->getMessage(),
            ]);
        } finally {
            $restore();
        }
    }

    /**
     * Revoke all access tokens for the user.
     */
    private function revokeUserTokens(User $user): void
    {
        DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->update(['revoked' => true]);
        
        Log::info("Force logged out user for User ID: {$user->id} due to status being set to {$user->status}");
    }
}
