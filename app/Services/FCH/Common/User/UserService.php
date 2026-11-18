<?php

namespace App\Services\FCH\Common\User;

use App\Services\FCH\Common\Notification\Notifier;
use Throwable;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Models\Invoice\Invoice;
use App\Models\Subscription\Subscription;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\Common\Invoice\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\ActivityLog\ActivityLog;
use Spatie\Permission\PermissionRegistrar;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\Global\Auth\UserResource;
use App\Services\Global\Notification\Mail\UserCreateNotificationMail;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Subscription\SubscriptionCreationService;
use App\Services\Global\Account\EmailChangeService;

use function Illuminate\Support\months;

class UserService
{
    /**
     * Create a new user and optionally link to employee
     */

    use PaginatesApi;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected ActivityLogService $activityLogService,
        protected SubscriptionCreationService $subscriptionCreationService,
        protected EmailChangeService $emailChange,
    ) {
    }

    /**
     * Create User
     */
    public function create(array $data, ?int $roleId = null): User
    {
        $authUser = auth()->user();
        $partnerId = $authUser->partner_id;
        $isPaid = false;

        // Check user limit if not host and not purchasing a paid seat
        $partner = Partner::find($partnerId);
        if ($partner && !$partner->is_host) {
            $isPaid = isset($data['is_paid']) && $data['is_paid'];
            if (!$isPaid) {
                // The same rule the Users screen's count uses (Partner::userSeatLimit).
                if (!$partner->hasFreeUserSeat()) {
                    throw new \Exception('User limit reached. Please purchase additional seats.');
                }
            }
        }

        $plainPassword = $data['password'] ?? bin2hex(random_bytes(4));

        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone_number' => $data['phone_number'] ?? null,
                'user_type' => $authUser->user_type,
                'password' => Hash::make($plainPassword),
                'partner_id' => $authUser->partner_id,
                'status' => $isPaid ? User::STATUS['inactive'] : User::STATUS['active'],
                // Every user is the login of one of the partner's employees -
                // StoreUserRequest guarantees employee_id is theirs and unlinked.
                'entity_type' => Employee::class,
                'entity_id' => $data['employee_id'],
                'is_paid' => $isPaid,
            ]);

            $roleId = $data['role_id'] ?? null;

            if ($roleId) {
                $this->assignRoleAndPermissions($user, $roleId);
            }
            if ($isPaid) {
                $partner = Partner::find($authUser->partner_id);
                $partnerSub = $partner ? $partner->subscription : null;
                $months = (int) ($data['months'] ?? 1);

                // partner->subscription->per_seat_amount can be null (never populated
                // for some partner subscriptions - see SubscriptionCreationService::
                // startSubscription()), and null * $months = 0, which is why this
                // invoice was being created with a $0 amount. Pass the raw (possibly
                // null) value through to createPendingUserSeatSubscription() as
                // before - it already falls back to the seat plan's own total_amount
                // when null - then read the invoice amount back FROM that resolved
                // subscription's payable_amount instead of trusting the raw value here.
                $requestedSeatAmount = $partnerSub ? $partnerSub->per_seat_amount : null;

                $subscription = $this->subscriptionCreationService->createPendingUserSeatSubscription(
                    User::class,
                    $user->id,
                    $partner,
                    $requestedSeatAmount,
                    $months
                );

                // payable_amount is already per_seat_amount * $months (see
                // createPendingUserSeatSubscription()) - do not multiply by $months
                // again here, that would charge for months^2.
                $amount = (float) ($subscription->payable_amount ?? (49.00 * $months));

                $invoiceId = $this->invoiceService->generateInvoiceId();
                $invoice = Invoice::create([
                    'invoice_id' => $invoiceId,
                    'partner_id' => $authUser->partner_id,
                    'subscription_id' => $subscription->id,
                    'entity_type' => Partner::class,
                    'entity_id' => $authUser->partner_id,
                    // The person billed, never the company (see InvoiceCustomerNameTest).
                    'customer_name' => $partner ? $partner->full_name : $user->name,
                    'email' => $partner ? $partner->email : $user->email,
                    'payment_amount' => $amount,
                    'month_count' => $months,
                    'status' => Invoice::STATUS_UNPAID,
                    'type' => Invoice::INVOICE_TYPE_USER_SEAT,
                    'is_partner_paying' => true,
                    'is_platform_fee' => false,
                ]);

                $this->activityLogService->logModelEvent(
                    $invoice,
                    ActivityLog::LOG_TYPE_INVOICE_CREATE
                );

                // Not a real column (users has no invoice_id) - this only carries the
                // invoice id back to the create response for the frontend's post-create
                // payment step, matching EmployeeService::create(). Without it, the
                // frontend never learns which invoice to fetch, so InvoicePaymentModal
                // never receives an invoice and the payment method selection inside it
                // never appears - see UserController::store() for the response side.
                $user->invoice_id = $invoice->id;

                // Update current cycle invoice
                $subscription->update([
                    'current_cycle_invoice_id' => $invoice->id,
                ]);

                // Send invoice mail to partner
                try {
                    $this->invoiceService->sendInvoice($invoice->id);
                } catch (Throwable $e) {
                    Log::error('Invoice created but email failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->activityLogService->logModelEvent(
                $user,
                ActivityLog::LOG_TYPE_USER_CREATE
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e; // user not created if DB fails
        }

        if ($user && !$isPaid) {
            // AFTER COMMIT — email section
            try {
                $userInfo = User::find($user->id);
                $this->sendCreatedMail($userInfo, $userInfo, $plainPassword);
            } catch (Throwable $e) {
                Log::error('User created but email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
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
        $query = User::query()->where('partner_id', Auth::user()->partner_id)->hideDevelopersFrom(Auth::user())->with(['roles', 'entity'])->orderBy('id', 'desc');

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
                'borrower',
                'entity' => function ($morphTo) {
                    $morphTo->morphWith([
                        Borrower::class => ['subscription'],
                        Partner::class => ['subscription'],
                        Employee::class => [], // optional (add relations if needed)
                    ]);
                }
            ]);

        $query->hideDevelopersFrom(Auth::user());

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
        $sortField = $filters['sort_field'] ?? 'id';
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
                    'Partner' => Partner::class,
                    'Borrower' => \App\Models\Borrower\Borrower::class,
                    'Admin' => null, // Admin usually has no entity_type
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
        if ($user->isDeveloper() && !Auth::user()->isDeveloper()) {
            throw new \Exception(__('user.not_authorized_to_update_developer'));
        }



        try {
            // A new sign-in email is confirmed from the new address before it
            // takes effect (and no longer resets the password).
            $newEmail = $this->emailChange->takeFrom($data, $user, $user->email);

            $passwordUpdated = false;
            $plainPassword = null;

            if (isset($data['password']) && $data['password'] !== null) {
                $plainPassword = $data['password'];
                $data['password'] = Hash::make($plainPassword);
                $passwordUpdated = true;
                Log::info('🔑 Password explicitly updated for User', ['user_id' => $user->id]);
            }

            $oldStatus = $user->status;

            // A login account linked to an employee is the same person, so the
            // details both records hold (name, email, phone) move together —
            // in one transaction, so the two can't end up disagreeing.
            DB::transaction(function () use ($user, $data) {
                $user->update($data);

                if ($user->wasChanged(['name', 'email', 'phone_number'])
                    && ($employee = $user->entity) instanceof Employee) {
                    $employee->syncFromUser($user);
                }
            });

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

            // 📧 Send update notification if the password was changed
            if ($passwordUpdated) {
                $this->sendPasswordChangedMail($user, $plainPassword);
            }

            if ($newEmail) {
                $this->emailChange->request($user, $newEmail);
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

            $role = Role::assignableBy($authUser)->findOrFail($roleId);
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
        $users = User::manageableBy(Auth::user())->whereIn('users.id', $ids)->get();

        foreach ($users as $user) {
            // Check if user is a developer and auth user is not
            if (!auth()->user()->isDeveloper() && $user->isDeveloper()) {
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
        $users = User::manageableBy(Auth::user())->whereIn('users.id', $ids)->get();
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

    /**
     * An administrator changed this login's password (Users > Change Account
     * Password). The new password stays in the email, but the email and the
     * bell say it was changed - not the new-account welcome (BUG-200).
     */
    public function sendPasswordChangedMail(User $user, string $plainPassword): void
    {
        try {
            app(Notifier::class)->send('user.account.login_updated', $user,
                mail: (new UserCreateNotificationMail([
                    'subject' => __('common.password_updated_subject'),
                    'user' => $user,
                    'plain_password' => $plainPassword,
                    'partner_id' => $user->partner_id,
                    'is_password_change' => true,
                ]))->to($user->email));
        } catch (Throwable $e) {
            Log::error('📧 Password changed mail failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendCreatedMail($user, $businessInfo, string $plainPassword, ?string $subject = null): void
    {
        $partnerId = $user->partner_id ?? null;

        try {
            $data = [
                'subject' => $subject,
                'user' => $user,
                'plain_password' => $plainPassword,
                'partner_id' => $partnerId,
            ];

            app(Notifier::class)->send('user.account.created', $user,
                mail: (new UserCreateNotificationMail($data))->to($user->email));
        } catch (Throwable $e) {
            Log::error('📧 UserCreateNotificationMail failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
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
