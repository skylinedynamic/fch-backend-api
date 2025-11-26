<?php

namespace App\Services\FCH\Partner;

use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Business\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Traits\PaginatesApi;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Partner\PartnerResource;

class PartnerService
{
    use PaginatesApi;
    /**
     * Field mapping for user table fields.
     */
    private array $userFieldMapping = [
        'full_name'      => 'name',
        'email'          => 'email',
        'phone_number'   => 'phone_number',
        'city'           => 'city',
        'state'          => 'state',
        'zip_code'       => 'postal_code',
        'country'        => 'country',
        'address_line_1' => 'address_line_1',
        'address_line_2' => 'address_line_2',
    ];

    /**
     * Field mapping for partner table fields.
     */
    private array $partnerFieldMapping = [
        'full_name'                   => 'full_name',
        'company_name'                => 'company_name',
        'email'                       => 'email',
        'phone_number'                => 'phone_number',
        'contact_person'              => 'contact_person',
        'contact_person_email'        => 'contact_person_email',
        'contact_person_phone_number' => 'contact_person_phone_number',
        'contact_person_relation'     => 'contact_person_relation',
        'partner_type'                => 'partner_type',
        'status'                      => 'status',
        'state'                       => 'state',
        'city'                        => 'city',
        'postal_code'                 => 'postal_code',
        'address_line_1'              => 'address_line_1',
        'address_line_2'              => 'address_line_2',
        'country'                     => 'country',
        'joining_date'                => 'joining_date',
        'company_logo'                => 'company_logo',
        'tax_id'                      => 'tax_id', // Assuming added to model fillable/migration if needed, or handled via additional_data if not
        'website'                     => 'website', // Same assumption
        'partner_image'               => 'partner_image',
    ];

    /**
     * Create a new partner.
     */
    public function store(array $data): Partner
    {
        DB::beginTransaction();
         try{
            // Create or update user
            $user = $this->createOrUpdateUser($data);
            
            // Create partner
            $partnerData = $this->mapPartnerData($data);
            
            $partner = Partner::create($partnerData);
            
            // Update user with partner link
            $user->partner_id = $partner->id;
            $user->entity_type = Partner::class;
            $user->entity_id = $partner->id;
            $user->save();

            DB::commit();
            return $partner;

         }catch(\Exception $e){
            DB::rollBack();
            throw $e;
         }
    }

    /**
     * Update an existing partner.
     */
    public function update(Partner $partner, array $data): Partner
    {
        return DB::transaction(function () use ($partner, $data) {
            // Update user
            // Assuming the partner has a linked user via polymorphic relation or we find by email
            $user = $partner->partnerUser ?? User::where('email', $data['email'] ?? '')->first();
            
            if ($user) {
                $this->createOrUpdateUser($data, $user);
            }
            
            // Update partner
            $partnerData = $this->mapPartnerData($data);
            $partner->update($partnerData);
            
            return $partner->fresh();
        });
    }

    /**
     * Find a partner by ID.
     */
    public function findById(int $id): ?Partner
    {
        return Partner::with('partnerUser')->find($id);
    }

    /**
     * Create or update user based on email.
     */
    private function createOrUpdateUser(array $data, ?User $existingUser = null): User
    {
        $userData = $this->mapUserData($data);
        
        if ($existingUser) {
            $existingUser->update($userData);
            return $existingUser;
        }
        
        // Check if user exists by email
        $user = User::where('email', $data['email'])->first();
        
        if ($user) {
            $user->update($userData);
            return $user;
        }
        
        // Create new user
        $userData['password'] = bcrypt('temporary_password_' . time());
        $userData['user_type'] = User::PARTNER;
    
        return User::create($userData);
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
        if (!empty($filters['partner_id'])) {
            $query->where('partners.id', $filters['partner_id']);
        }

        // 🔍 Filter by assigned_user_id (exact match)
        if (!empty($filters['assigned_user_id'])) {
            $query->where('partners.assign_to', $filters['assigned_user_id']);
        }

        // 🔍 Search filter (uses scopeSearch from Partner model)
        if (!empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Handle special field aliases
        if ($sortField === 'assigned_user_name') {
            $sortField = 'assign_to';
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'assigned_user_name' => 'assign_to',
                ],
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
                'id'                   => $partner->id,
                'assigned_user_id'     => $assignedUser->id ?? null,
                'assigned_user_name'   => $assignedUser->name ?? null,
                'full_name'            => $partner->full_name,
                'company_name'         => $partner->company_name,
                'email'                => $partner->email,
                'phone_number'         => $partner->phone_number,
                'contact_person'       => $partner->contact_person,
                'contact_person_email' => $partner->contact_person_email,
                'contact_person_phone' => $partner->contact_person_phone_number,
                'partner_type'         => $partner->partner_type,
                'status'               => $partner->status,
                'joining_date'         => $partner->joining_date,
            ];
        })->all();

        // Return data with pagination meta and statistics
        return [
            'data' => $data,
            'meta' => [
                'current_page'  => $partners->currentPage(),
                'from'          => $partners->firstItem(),
                'to'            => $partners->lastItem(),
                'per_page'      => $partners->perPage(),
                'total'         => $partners->total(),
                'last_page'     => $partners->lastPage(),
                'next_page_url' => $partners->nextPageUrl(),
                'prev_page_url' => $partners->previousPageUrl(),
                'links'         => $partners->linkCollection(),
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
                $query->where('user_type', 'App\\Models\\Employee\\Employee')
                      ->orWhereNull('entity_type');
            })
            ->select('id', 'name','email','phone_number')
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
}