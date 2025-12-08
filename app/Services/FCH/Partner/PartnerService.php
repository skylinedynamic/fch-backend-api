<?php

namespace App\Services\FCH\Partner;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Borrower\BorrowerPartnerResource;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Models\Borrower\Borrower;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

class PartnerService
{
    use PaginatesApi, UploadsFiles;

    /**
     * Field mapping for user table fields.
     */
    private array $userFieldMapping = [
        'full_name' => 'name',
        'email' => 'email',
        'phone_number' => 'phone_number',
        'city' => 'city',
        'state' => 'state',
        'postal_code' => 'postal_code',
        'country' => 'country',
        'address_line_1' => 'address_line_1',
        'address_line_2' => 'address_line_2',
    ];

    /**
     * Field mapping for partner table fields.
     */
    private array $partnerFieldMapping = [
        'full_name' => 'full_name',
        'company_name' => 'company_name',
        'email' => 'email',
        'phone_number' => 'phone_number',
        'contact_person' => 'contact_person',
        'contact_person_email' => 'contact_person_email',
        'contact_person_phone_number' => 'contact_person_phone_number',
        'contact_person_relation' => 'contact_person_relation',
        'partner_type' => 'partner_type',
        'status' => 'status',
        'state' => 'state',
        'city' => 'city',
        'address_line_1' => 'address_line_1',
        'postal_code' => 'postal_code',
        'address_line_2' => 'address_line_2',
        'country' => 'country',
        'country_code' => 'country_code',
        'joining_date' => 'joining_date',
        'company_logo' => 'company_logo',
        'tax_id' => 'tax_id',
        'website' => 'website',
        'partner_image' => 'partner_image',
        'assign_to' => 'assign_to', // Assigned agent/user
    ];

    /**
     * Create a new partner.
     */
    /**
     * Create a new partner.
     */
    public function store(array $data): array
    {
        DB::beginTransaction();
        try {
            // Create or update user
            $userResult = $this->createOrUpdateUser($data);
            $user = $userResult['user'];
            $plainPassword = $userResult['plain_password'];

            // Create partner
            $partnerData = $this->mapPartnerData($data);

            $partner = Partner::create($partnerData);

            // Handle partner_image upload
            if (isset($partnerData['partner_image']) && $partnerData['partner_image'] instanceof UploadedFile) {
                $upload = $this->uploadFile($partnerData['partner_image'], "partners/{$partner->id}", 'public');
                $partner->update(['partner_image' => $upload['path']]);
                Log::info('🖼️ Partner image uploaded', ['path' => $upload['path']]);
            }

            // Handle company_logo upload
            if (isset($partnerData['company_logo']) && $partnerData['company_logo'] instanceof UploadedFile) {
                $upload = $this->uploadFile($partnerData['company_logo'], "partners/{$partner->id}", 'public');
                $partner->update(['company_logo' => $upload['path']]);
                Log::info('🏢 Company logo uploaded', ['path' => $upload['path']]);
            }

            // Update user with partner link
            $user->partner_id = $partner->id;
            $user->entity_type = Partner::class;
            $user->entity_id = $partner->id;
            $user->save();

            // Create default roles for the partner and assign Admin role to user
            $this->createPartnerRoles($partner->id, $user);

            DB::commit();

            return [
                'partner' => $partner->fresh(),
                'user' => $user->fresh(),
                'plain_password' => $plainPassword,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Partner creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create default roles (Admin and Borrower) for a new partner.
     * Assigns the Admin role to the partner's user.
     * Permissions are filtered based on partner's is_host status.
     */
    private function createPartnerRoles(int $partnerId, User $user): void
    {
        try {
            // Clear cached permissions before creating roles
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Get partner to check is_host status
            $partner = Partner::find($partnerId);

            if (! $partner) {
                throw new \Exception("Partner with ID {$partnerId} not found");
            }

            // Filter permissions based on is_host status
            // Host partners (is_host = 1) get ALL permissions
            // Regular partners (is_host = 0) only get permissions where is_host = 0
            $allowedPermissions = Permission::where('guard_name', 'api')
                ->where('is_host', 0)
                ->get();

            if ($allowedPermissions->isEmpty()) {
                Log::warning('⚠️ No API permissions found. Roles will be created without permissions.');
            }

            // Create or update Admin role with filtered permissions
            // Using updateOrCreate to avoid duplicate role name errors (partner-wise roles)
            $adminRole = Role::updateOrCreate(
                ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partnerId],
                [
                    'name' => 'Admin',
                    'guard_name' => 'api',
                    'partner_id' => $partnerId,
                    'code' => 'admin',
                    'is_static' => true,
                ]
            );

            // Assign Admin role to the partner's user
            $user->assignRole($adminRole);

            // Also sync permissions directly to user (like EmployeeService)
            if ($allowedPermissions->isNotEmpty()) {
                $user->syncPermissions($allowedPermissions);
            }

            Log::info('✅ Admin role and permissions assigned to user', [
                'user_id' => $user->id,
                'role_id' => $adminRole->id,
            ]);

            // Create or update Borrower role with no permissions
            $borrowerRole = Role::updateOrCreate(
                ['name' => 'Borrower', 'guard_name' => 'api', 'partner_id' => $partnerId],
                [
                    'name' => 'Borrower',
                    'guard_name' => 'api',
                    'partner_id' => $partnerId,
                    'code' => 'borrower',
                    'is_static' => false,
                ]
            );

            // Borrower role gets no permissions (empty sync)
            $borrowerRole->syncPermissions([]);

            Log::info('✅ Partner roles created successfully', [
                'partner_id' => $partnerId,
                'admin_role_id' => $adminRole->id,
                'borrower_role_id' => $borrowerRole->id,
                'user_id' => $user->id,
                'permissions_assigned' => $allowedPermissions->count(),
                'is_host' => $partner->is_host,
            ]);

            // Clear cached permissions after role creation
            app(PermissionRegistrar::class)->forgetCachedPermissions();

        } catch (\Exception $e) {
            Log::error('❌ Failed to create partner roles', [
                'partner_id' => $partnerId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing partner.
     */
    public function update(Partner $partner, array $data): Partner
    {
        DB::beginTransaction();
        try {
            // Handle partner_image upload
            if (isset($data['partner_image']) && $data['partner_image'] instanceof UploadedFile) {
                // Delete old image if exists
                if ($partner->partner_image) {
                    $this->deleteFile($partner->partner_image, 'public');
                }
                $upload = $this->uploadFile($data['partner_image'], "partners/{$partner->id}", 'public');
                $data['partner_image'] = $upload['path'];
                Log::info('🖼️ Partner image updated', ['path' => $upload['path']]);
            }

            // Handle company_logo upload
            if (isset($data['company_logo']) && $data['company_logo'] instanceof UploadedFile) {
                // Delete old logo if exists
                if ($partner->company_logo) {
                    $this->deleteFile($partner->company_logo, 'public');
                }
                $upload = $this->uploadFile($data['company_logo'], "partners/{$partner->id}", 'public');
                $data['company_logo'] = $upload['path'];
                Log::info('🏢 Company logo updated', ['path' => $upload['path']]);
            }

            // Update user
            // Assuming the partner has a linked user via polymorphic relation or we find by email
            $user = $partner->partnerUser ?? User::where('email', $data['email'] ?? '')->first();

            if ($user) {
                $this->createOrUpdateUser($data, $user);
            }

            // Update partner
            $partnerData = $this->mapPartnerData($data);
            $partner->update($partnerData);

            DB::commit();
            Log::info('✅ Partner updated successfully', ['partner_id' => $partner->id]);

            return $partner->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('❌ Partner update failed', [
                'partner_id' => $partner->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Find a partner by ID.
     */
    public function findById(int $id): ?Partner
    {
        return Partner::with('partnerUser')->find($id);
    }

    /**
     * Get partner details by ID with all relationships.
     */
    public function getDetails(int $id): ?Partner
    {
        return Partner::with(['assignedUser', 'partnerUser'])
            ->find($id);
    }

    /**
     * Create or update user based on email.
     */
    private function createOrUpdateUser(array $data, ?User $existingUser = null): array
    {
        $userData = $this->mapUserData($data);
        $plainPassword = null;

        if ($existingUser) {
            $existingUser->update($userData);

            return ['user' => $existingUser, 'plain_password' => null];
        }

        // Check if user exists by email
        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $user->update($userData);

            return ['user' => $user, 'plain_password' => null];
        }

        // Create new user
        $plainPassword = bin2hex(random_bytes(4));
        $userData['password'] = bcrypt($plainPassword);
        $userData['user_type'] = User::PARTNER;

        $user = User::create($userData);

        return ['user' => $user, 'plain_password' => $plainPassword];
    }

    /**
     * Map request data to user model attributes.
     */
    private function mapUserData(array $data): array
    {
        $userData = [];

        foreach ($this->userFieldMapping as $requestField => $userField) {
            if (array_key_exists($requestField, $data)) {
                $userData[$userField] = $data[$requestField];
            }
        }

        return $userData;
    }

    /**
     * List partners with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        // Base query: Start from Partner model with User eager loading
        $query = Partner::query()
            ->with(['assignedUser' => function ($userQuery) {
                $userQuery->select('id', 'name');
            }]);

        // Filter based on authenticated user's host status
        if ($authUser && $authUser->partner && $authUser->partner->is_host == 1) {
            // If logged-in user is a host, show only non-host partners
            $query->where('is_host', '!=', 1);
        } else {
            // Otherwise, show only host partners
            $query->where('is_host', 1);
        }

        // 🔍 Filter by partner_id (exact match)
        if (! empty($filters['partner_id'])) {
            $query->where('partners.id', $filters['partner_id']);
        }

        // 🔍 Filter by assigned_user_id (exact match)
        if (! empty($filters['assigned_user_id'])) {
            $query->where('partners.assign_to', $filters['assigned_user_id']);
        }

        // 🔍 Search filter (uses scopeSearch from Partner model)
        if (! empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $skipSorting = false;

        // Handle special field aliases
        if ($sortField === 'assigned_user_name') {
            // Join users table to sort by name
            $query->leftJoin('users as assigned_users', 'partners.assign_to', '=', 'assigned_users.id')
                ->orderBy('assigned_users.name', $sortOrder)
                ->select('partners.*'); // Ensure we still only select partner fields

            $skipSorting = true;
        }

        // 🔍 Filter by assigned_user_name (related user name)
        if (isset($filters['filters']['assigned_user_name'])) {
            $assignedUserFilter = $filters['filters']['assigned_user_name'];
            // Check if it has constraints
            if (isset($assignedUserFilter['constraints']) && is_array($assignedUserFilter['constraints'])) {
                $hasActiveConstraint = false;
                foreach ($assignedUserFilter['constraints'] as $c) {
                    if (! empty($c['value'])) {
                        $hasActiveConstraint = true;
                        break;
                    }
                }

                if ($hasActiveConstraint) {
                    $query->whereHas('assignedUser', function ($q) use ($assignedUserFilter) {
                        $q->where(function ($subQ) use ($assignedUserFilter) {
                            foreach ($assignedUserFilter['constraints'] as $constraint) {
                                $value = $constraint['value'] ?? null;
                                $matchMode = $constraint['matchMode'] ?? 'contains';

                                if ($value) {
                                    // Apply logic based on matchMode
                                    $likeContains = "%{$value}%";
                                    $likeStarts = "{$value}%";
                                    $likeEnds = "%{$value}";

                                    if ($matchMode === 'contains') {
                                        $subQ->where('name', 'like', $likeContains);
                                    } elseif ($matchMode === 'startsWith') {
                                        $subQ->where('name', 'like', $likeStarts);
                                    } elseif ($matchMode === 'endsWith') {
                                        $subQ->where('name', 'like', $likeEnds);
                                    } else {
                                        $subQ->where('name', '=', $value);
                                    }
                                }
                            }
                        });
                    });
                }
            }
            // Unset to prevent QueryFilter from processing it incorrectly
            unset($filters['filters']['assigned_user_name']);
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [],
                'skip_sorting' => $skipSorting,
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            PartnerResource::class,
            __('partner.success.retrieved')
        );
    }

    /**
     * Get all partners with their user information (legacy method with statistics).
     */
    public function getAllPartners(Request $request)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        // Base query: Start from Partner model with User eager loading
        $query = Partner::query()
            ->with(['assignedUser' => function ($userQuery) {
                $userQuery->select('id', 'name');
            }])
            ->select(
                'partners.id',
                'partners.company_name',
                'partners.full_name',
                'partners.contact_person',
                'partners.contact_person_email',
                'partners.contact_person_phone_number',
                'partners.email',
                'partners.phone_number',
                'partners.partner_type',
                'partners.is_host',
                'partners.assign_to',
                'partners.created_at'
            );

        // Filter based on authenticated user's host status
        $hostFilter = null;
        if ($authUser && $authUser->partner && $authUser->partner->is_host == 1) {
            // If logged-in user is a host, show only non-host partners
            $query->where('is_host', '!=', 1);
            $hostFilter = ['is_host', '!=', 1];
        } else {
            // Otherwise, show only host partners
            $query->where('is_host', 1);
            $hostFilter = ['is_host', '=', 1];
        }

        // 🔍 Filter by partner_id (exact match)
        if ($partnerId = $request->get('partner_id')) {
            $query->where('partners.id', $partnerId);
        }

        // 🔍 Filter by assigned_user_id (exact match)
        if ($assignedUserId = $request->get('assigned_user_id')) {
            $query->where('partners.assign_to', $assignedUserId);
        }

        // 🔍 Search filter (searches all partner and user fields)
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%") // Changed from email
                    ->orWhere('phone_number', 'LIKE', "%{$search}%") // Changed from phone_number
                    ->orWhere('full_name', 'LIKE', "%{$search}%") // Changed from owner_name
                    ->orWhere('contact_person', 'LIKE', "%{$search}%")
                    ->orWhere('partner_type', 'LIKE', "%{$search}%")
                    ->orWhereHas('assignedUser', function ($userQ) use ($search) {
                        $userQ->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        // 📄 Pagination (default 15 per page)
        $partners = $query->orderByDesc('partners.id')->paginate($request->get('per_page', 15));

        // Transform to the required flat structure
        $data = $partners->map(function ($partner) {
            $assignedUser = $partner->assignedUser;

            return [
                'id' => $partner->id,
                'assigned_user_id' => $assignedUser->id ?? null,
                'assigned_user_name' => $assignedUser->name ?? null,
                'full_name' => $partner->full_name,
                'company_name' => $partner->company_name,
                'email' => $partner->email,
                'phone_number' => $partner->phone_number,
                'contact_person' => $partner->contact_person,
                'contact_person_email' => $partner->contact_person_email,
                'contact_person_phone' => $partner->contact_person_phone_number,
                'partner_type' => $partner->partner_type,
                'status' => $partner->status,
                'joining_date' => $partner->joining_date,
            ];
        })->all();

        // Return data with pagination meta and statistics
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $partners->currentPage(),
                'from' => $partners->firstItem(),
                'to' => $partners->lastItem(),
                'per_page' => $partners->perPage(),
                'total' => $partners->total(),
                'last_page' => $partners->lastPage(),
                'next_page_url' => $partners->nextPageUrl(),
                'prev_page_url' => $partners->previousPageUrl(),
                'links' => $partners->linkCollection(),
            ],

        ];
    }

    /**
     * Get all users where entity_type is Employee or NULL.
     * Returns only id and name.
     */
    public function getAllUsers()
    {
        $users = User::query()
            ->where(function ($query) {
                $query->where('entity_type', Employee::class)
                    ->orWhereNull('entity_type');
            })
            ->select('id', 'name', 'email', 'phone_number')
            ->orderBy('name', 'asc')
            ->get();

        return $users;
    }

    /**
     * Map request data to partner model attributes.
     */
    private function mapPartnerData(array $data): array
    {
        $partnerData = [];

        foreach ($this->partnerFieldMapping as $requestField => $partnerField) {

            if (array_key_exists($requestField, $data)) {
                $partnerData[$partnerField] = $data[$requestField];
            }
        }

        return $partnerData;
    }

    /**
     * Get Borrowers list.
     */
    public function getBorrowers(array $filters)
    {
        
        $query = Borrower::query()->with('partner');

        // Filter by partner_id
        if (! empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';

        // Handle full_name sorting by using first_name as proxy
        if ($sortField === 'full_name') {
            $sortField = 'first_name';
        }

        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Apply QueryFilter
        // Search across multiple fields
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('business_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone_number', 'LIKE', "%{$search}%")
                    ->orWhere('business_start_date', 'LIKE', "%{$search}%")
                // Search concatenated full_name (first_name + last_name)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'company_name' => 'business_name',
                ],
                'concat_fields' => [
                    'full_name' => ['first_name', 'last_name'],
                ],
            ]
        );

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);
       
        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerPartnerResource::class,
            __('partner.borrowers_retrieved_data')
        );
    }

    /**
     * Delete Partner
     */
    public function delete(array $ids)
    {
        try {
            // Get partners to delete their images
            $partners = Partner::whereIn('id', $ids)->get();
            foreach ($partners as $partner) {
                if ($partner->partner_image) {
                    $this->deleteFile($partner->partner_image, 'public');
                }
            }

            // Delete partner records and associated users
            User::whereIn('partner_id', $ids)->delete();
            Partner::whereIn('id', $ids)->delete();

            return ApiResponse::success(
                [],
                __('partner.success.deleted')
            );
        } catch (\Throwable $e) {
            return ApiResponse::serverError(__('partner.partner_delete_failed'), 500);
        }
    }

    /**
     * Get Partner Statistics Counts.
     */
    public function getPartnerStats(): array
    {
        $today = now();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();

        return [
            'active_count' => Partner::where('status', 'active')->count(),
            'new_this_month' => Partner::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
            'total_partners' => Partner::count(),
            'inactive_count' => Partner::where('status', 'inactive')->count(),
        ];
    }

    public function getPartnerProfileStats(int $id)
    {
        $stats = DB::table('partners as p')
            ->select(
                'p.id',
                DB::raw('(SELECT COUNT(*) FROM appointments WHERE partner_id = '.$id.') as total_meetings'),
                DB::raw('(SELECT COUNT(*) FROM borrowers WHERE partner_id = '.$id.') as total_clients'),
                DB::raw('(SELECT COUNT(*) FROM invoices WHERE partner_id = '.$id.') as total_invoice'),
                DB::raw('(SELECT COUNT(*) FROM support_tickets WHERE partner_id = '.$id.') as total_tickets'),
                DB::raw('(SELECT enrollment_type FROM partner_subscriptions 
                      WHERE partner_id = '.$id.' 
                      ORDER BY id DESC 
                      LIMIT 1) as enrollment_type')
            )
            ->where('p.id', $id)
            ->first();

        return $stats;
    }
}
