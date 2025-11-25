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
            $companyData['status'] = Business::STATUS_PENDING;

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
            ->with(['partnerUser' => function ($userQuery) {
                $userQuery->select('id', 'name', 'email', 'entity_type', 'entity_id');
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
                'partners.status',
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

        // 🔍 Filter by user_id (exact match)
        if ($userId = $request->get('user_id')) {
            $query->whereHas('partnerUser', function ($userQ) use ($userId) {
                $userQ->where('id', $userId);
            });
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
                  ->orWhereHas('partnerUser', function ($userQ) use ($search) {
                      $userQ->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%")
                            ->orWhere('id', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Calculate statistics counts (before pagination)
        $statsQuery = Partner::query()->where($hostFilter[0], $hostFilter[1], $hostFilter[2]);
        
        // Active partners count (Approved status)
        $activePartnersCount = (clone $statsQuery)->where('status', Partner::STATUS_APPROVED)->count();
        
        // Inactive partners count (On Hold + Declined statuses)
        $inactivePartnersCount = (clone $statsQuery)
            ->whereIn('status', [Partner::STATUS_ON_HOLD, Partner::STATUS_DECLINED])
            ->count();
        
        // Pending partners count
        $pendingPartnersCount = (clone $statsQuery)->where('status', Partner::STATUS_PENDING)->count();
        
        // Current month partners count (Pending + Approved created in current month)
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();
        $currentMonthPartnersCount = (clone $statsQuery)
            ->whereIn('status', [Partner::STATUS_PENDING, Partner::STATUS_APPROVED])
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->count();

        // 📄 Pagination (default 15 per page)
        $partners = $query->orderByDesc('partners.id')->paginate($request->get('per_page', 15));

        // Transform to the required flat structure
        $data = $partners->map(function ($partner) {
            $user = $partner->partnerUser;
            return [
                'id'                   => $partner->id,
                'user_id'              => $user->id ?? null,
                'user_name'            => $user->name ?? null,
                'company_name'         => $partner->company_name,
                'owner_name'           => $partner->owner_name,
                'owner_email'          => $partner->owner_email,
                'owner_phone'          => $partner->owner_phone_number,
                'contact_person'       => $partner->contact_person,
                'contact_person_email' => $partner->contact_person_email ?? $user->email ?? null,
                'contact_person_phone' => $partner->contact_person_phone_number,
                'company_email'        => $partner->company_email,
                'company_phone'        => $partner->company_phone_number,
                'partner_type'         => $partner->partner_type,
                'status'               => $partner->status,
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
            'statistics' => [
                'active_partners_count'         => $activePartnersCount,
                'inactive_partners_count'       => $inactivePartnersCount,
                'pending_partners_count'        => $pendingPartnersCount,
                'current_month_partners_count'  => $currentMonthPartnersCount,
            ],
        ];
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