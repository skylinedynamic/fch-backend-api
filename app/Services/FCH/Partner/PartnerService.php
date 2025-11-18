<?php

namespace App\Services\FCH\Partner;

use App\Models\User\User;
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
     * Get all partners with their company information.
     */
    public function getAllPartners(Request $request)
    {
        $query = User::with('company')
            ->where('user_type', User::PARTNER);

        // 🔍 Search filter
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                ->orWhere('last_name', 'LIKE', "%{$search}%")
                ->orWhere('email', 'LIKE', "%{$search}%")
                ->orWhereHas('company', function ($companyQuery) use ($search) {
                    $companyQuery->where('company_name', 'LIKE', "%{$search}%");
                });
            });
        }

        // 📄 Pagination (with links)
        $partners = $query->orderByDesc('id')->paginate($request->get('per_page', 1));

        // Add pagination metadata (including links)
        return [
            'data' => $partners->items(),
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