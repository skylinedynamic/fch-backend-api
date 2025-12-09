<?php

namespace App\Services\FCH\Common\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerSignupId;
use App\Models\User\User;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Borrower\BorrowerResource;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use App\Helpers\ApiResponse;

class BorrowerService
{
    use PaginatesApi, UploadsFiles;

    /**
     * List borrowers with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        $query = Borrower::query()
            ->with(['partner:id,full_name', 'assignedUser:id,name'])
            ->select([
                'borrowers.id',
                'borrowers.first_name',
                'borrowers.last_name',
                'borrowers.email',
                'borrowers.phone_number',
                'borrowers.assign_id',
                'borrowers.partner_id',
                'borrowers.current_tier',
            ]);

        // Filter based on authenticated user's partner and host status
        if ($authUser && $authUser->partner) {
            if ($authUser->partner->is_host == 1) {
                // If user is a host, filter by partner_id if provided, otherwise show all
                if (! empty($filters['partner_id'])) {
                    $query->where('borrowers.partner_id', $filters['partner_id']);
                }
                // If no partner_id filter provided, show all borrowers (no restriction)
            } else {
                // If user is not a host, only show borrowers from their own partner
                $query->where('borrowers.partner_id', $authUser->partner_id);
            }
        }

        // Filter by current_tier
        if (! empty($filters['current_tier'])) {
            $query->where('borrowers.current_tier', $filters['current_tier']);
        }

        // Filter by assign_id
        if (! empty($filters['assign_id'])) {
            $query->where('borrowers.assign_id', $filters['assign_id']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';

        // Handle full_name sorting by using first_name as proxy
        if ($sortField === 'full_name') {
            $sortField = 'first_name';
        }

        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $skipSorting = false;

        // Handle assign_name sorting
        if ($sortField === 'assign_name') {
            $query->leftJoin('users as assigned_users', 'borrowers.assign_id', '=', 'assigned_users.id')
                ->orderBy('assigned_users.name', $sortOrder)
                ->select([
                    'borrowers.id',
                    'borrowers.first_name',
                    'borrowers.last_name',
                    'borrowers.email',
                    'borrowers.phone_number',
                    'borrowers.assign_id',
                    'borrowers.partner_id',
                    'borrowers.current_tier',
                ]);
            $skipSorting = true;
        }

        // Search across multiple fields
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone_number', 'LIKE', "%{$search}%")
                    ->orWhere('current_tier', 'LIKE', "%{$search}%")
                // Search concatenated full_name (first_name + last_name)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        // 🔍 Filter by assign_name (related user name)
        if (isset($filters['filters']['assign_name'])) {
            $assignNameFilter = $filters['filters']['assign_name'];
            if (isset($assignNameFilter['constraints']) && is_array($assignNameFilter['constraints'])) {
                $hasActiveConstraint = false;
                foreach ($assignNameFilter['constraints'] as $c) {
                    if (!empty($c['value'])) {
                        $hasActiveConstraint = true;
                        break;
                    }
                }

                if ($hasActiveConstraint) {
                    $query->whereHas('assignedUser', function ($q) use ($assignNameFilter) {
                        $q->where(function ($subQ) use ($assignNameFilter) {
                            foreach ($assignNameFilter['constraints'] as $constraint) {
                                $value = $constraint['value'] ?? null;
                                $matchMode = $constraint['matchMode'] ?? 'contains';

                                if ($value) {
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
            unset($filters['filters']['assign_name']);
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'concat_fields' => [
                    'full_name' => ['first_name', 'last_name'],
                ],
                'skip_sorting' => $skipSorting,
            ]
        );

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerResource::class,
            __('borrower.success.retrieved')
        );
    }

    /**
     * Store a new borrower.
     */
    public function store(array $data)
    {
        DB::beginTransaction();
        try {
            // Handle file uploads if any (e.g., business_logo)
            // Note: The migration has 'business_logo' string column.
            
            $borrower = Borrower::create($data);

            if (isset($data['business_logo']) && $data['business_logo'] instanceof UploadedFile) {
                $upload = $this->uploadFile($data['business_logo'], "borrowers/{$borrower->id}", 'public');
                $borrower->update(['business_logo' => $upload['path']]);
            }

            // Remove used signup_id
            if (!empty($data['signup_id']) && !empty($data['partner_id'])) {
                BorrowerSignupId::where('signup_id', $data['signup_id'])
                    ->where('partner_id', $data['partner_id'])
                    ->delete();
            }

            // Create associated User if needed
            $userDataResult = null;
            if (!empty($data['email'])) {
                Log::info('Email send');
                $userDataResult = $this->createOrUpdateUser($data, $borrower);
            }

            DB::commit();
            
            return [
                'borrower' => $borrower->fresh(),
                'user' => $userDataResult['user'] ?? null,
                'plain_password' => $userDataResult['plain_password'] ?? null,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Borrower creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update an existing borrower.
     */
    public function update(Borrower $borrower, array $data)
    {
        DB::beginTransaction();
        try {
            if (isset($data['business_logo']) && $data['business_logo'] instanceof UploadedFile) {
                if ($borrower->business_logo) {
                    $this->deleteFile($borrower->business_logo, 'public');
                }
                $upload = $this->uploadFile($data['business_logo'], "borrowers/{$borrower->id}", 'public');
                $data['business_logo'] = $upload['path'];
            }

            $borrower->update($data);

             // Update associated User if exists
             if ($borrower->user) {
                $this->createOrUpdateUser($data, $borrower, $borrower->user);
            } elseif (!empty($data['email'])) {
                 // Create user if email is provided and user doesn't exist
                 $this->createOrUpdateUser($data, $borrower);
            }

            DB::commit();
            
            // Return consistent format or just borrower? Controller expects resource.
            // Keeping it simple as controller will be updated to handle array for store, but update often doesn't need email.
            return $borrower->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Borrower update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get borrower details.
     */
    public function getDetails(int $id)
    {
        return Borrower::with('partner')->find($id);
    }

    /**
     * Delete borrowers.
     */
    public function delete(array $ids)
    {
        try {
            // Delete associated users first
            User::where('entity_type', Borrower::class)->whereIn('entity_id', $ids)->delete();
            Borrower::whereIn('id', $ids)->delete();
            
            return ApiResponse::success([], __('borrower.success.deleted'));
        } catch (\Throwable $e) {
            return ApiResponse::serverError(__('borrower.error.delete_failed'), 500);
        }
    }

    /**
     * Create or update user for borrower.
     */
    private function createOrUpdateUser(array $data, Borrower $borrower, ?User $existingUser = null)
    {
        $userData = [
            'name' => $data['first_name'] . ' ' . $data['last_name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
        ];

        if ($existingUser) {
            $existingUser->update($userData);
            return ['user' => $existingUser, 'plain_password' => null];
        }

        // Check if user exists by email to avoid duplicates if not linked
        $user = User::where('email', $data['email'])->first();
        if ($user) {
             $user->update($userData);
             return ['user' => $user, 'plain_password' => null];
        }

        $plainPassword = bin2hex(random_bytes(8));
        $userData['password'] = bcrypt($plainPassword);
        
        $newUser = User::create($userData);

        return ['user' => $newUser, 'plain_password' => $plainPassword];
    }

    /**
     * Get all users where entity_type is Employee or NULL.
     * Returns only id, name, email, phone_number.
     * 
     * If auth user is_host == 1: returns all users
     * Otherwise: returns only users filtered by partner_id
     */
    public function getAllUsers()
    {
        // Get authenticated user
        $authUser = auth()->user();
        
        // Base query: users where entity_type is Employee or NULL
        $query = User::query()
            ->where(function ($q) {
                $q->where('entity_type', 'App\\Models\\Borrower\\Borrower')
                    ->orWhereNull('entity_type');
            });

        // Apply partner_id filter if user is NOT a host
        if ($authUser && $authUser->partner) {
            // Check if the authenticated user's partner is a host
            if ($authUser->partner->is_host != 1) {
                // Not a host: filter by partner_id
                $query->where('partner_id', $authUser->partner_id);
            }
            // If is_host == 1: no filter applied, returns all users
        }

        $users = $query
            ->select('id', 'name', 'email', 'phone_number')
            ->orderBy('name', 'asc')
            ->get();

        return $users;
    }
}
