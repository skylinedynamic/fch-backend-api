<?php

namespace App\Services\FCH\Partner;

use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Business\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
class PartnerService
{
    /**
     * Field mapping for user table fields.
     */
    private array $userFieldMapping = [
        'partner_name'   => 'first_name',
        'email'          => 'email',
        'phone_number'   => 'phone_number',
        'city'           => 'city',
        'state'          => 'state',
        'zip_code'       => 'postal_code',
        'country'        => 'country',
    ];

    /**
     * Field mapping for company table fields.
     */
    private array $companyFieldMapping = [
        'company_name'   => 'company_name',
        'website'        => 'company_website',
    ];

    /**
     * Create a new partner/company.
     */
    public function store(array $data): User
    {
        DB::beginTransaction();
         try{
            // Create or update user
            $user = $this->createOrUpdateUser($data);
            
            // Create company
            $companyData = $this->mapCompanyData($data);
            $companyData['user_id'] = $user->id;

            Business::create($companyData);
            DB::commit();
            return $user;

         }catch(\Exception $e){
            DB::rollBack();
            throw $e;
         }
    }

    /**
     * Update an existing partner/company.
     */
    public function update(User $partner, array $data): User
    {
        return DB::transaction(function () use ($partner, $data) {
            // Update user
            $this->createOrUpdateUser($data, $partner);
            
            // Update company
            $companyData = $this->mapCompanyData($data);
            $partner->update($companyData);
            
            return $partner->fresh();
        });
    }

    /**
     * Find a partner/company by ID.
     */
    public function findById(int $id): ?User
    {
        return User::find($id);
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
     * Get all partners with their user information.
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
                'partners.owner_name',
                'partners.owner_email',
                'partners.owner_phone_number',
                'partners.contact_person',
                'partners.contact_person_email',
                'partners.contact_person_phone_number',
                'partners.company_email',
                'partners.company_phone_number',
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
                  ->orWhere('company_email', 'LIKE', "%{$search}%")
                  ->orWhere('company_phone_number', 'LIKE', "%{$search}%")
                  ->orWhere('owner_name', 'LIKE', "%{$search}%")
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
                'company_name'         => $partner->company_name,
                'owner_name'           => $partner->owner_name,
                'owner_email'          => $partner->owner_email,
                'owner_phone'          => $partner->owner_phone_number,
                'contact_person'       => $partner->contact_person,
                'contact_person_email' => $partner->contact_person_email,
                'contact_person_phone' => $partner->contact_person_phone_number,
                'company_email'        => $partner->company_email,
                'company_phone'        => $partner->company_phone_number,
                'partner_type'         => $partner->partner_type,
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
                $query->where('entity_type', 'App\\Models\\Employee\\Employee')
                      ->orWhereNull('entity_type');
            })
            ->select('id', 'name')
            ->orderBy('name', 'asc')
            ->get();

        return $users;
    }

    /**
     * Map request data to company model attributes.
     */
    private function mapCompanyData(array $data): array
    {
        $companyData = [];

        foreach ($this->companyFieldMapping as $requestField => $companyField) {
            if (array_key_exists($requestField, $data)) {
                $companyData[$companyField] = $data[$requestField];
            }
        }

        return $companyData;
    }
}