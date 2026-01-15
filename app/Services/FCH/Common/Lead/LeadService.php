<?php

namespace App\Services\FCH\Common\Lead;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Lead\LeadResource;
use App\Models\Lead\Lead;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Models\CreditBuilder\Tier;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class LeadService
{
    use PaginatesApi;

    public function __construct() {}

    /**
     * List leads with pagination and filtering.
     */
    public function list(array $filters)
    {
        $authUser = Auth::user();
        $query = Lead::query()
            ->with(['salesperson', 'partner'])
            ->select('leads.*');

        // Filter based on host status
        if ($authUser && $authUser->partner) {
            if ($authUser->partner->is_host != 1) {
                // Non-hosts see only Borrower leads and their own partner's leads
                $query->where('leads.lead_type', 'Borrower');
                $query->where('leads.partner_id', $authUser->partner_id);
            } else {
                // Hosts can see all or filter by partner_id
                if (! empty($filters['partner_id'])) {
                    $query->where('leads.partner_id', $filters['partner_id']);
                }
            }
        }

        // Top-level Dropdown Filters
        if (! empty($filters['lead_type'])) {
            $query->where('leads.lead_type', $filters['lead_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('leads.status', $filters['status']);
        }

        if (! empty($filters['salesperson_id'])) {
            $query->where('leads.salesperson_id', $filters['salesperson_id']);
        }

        // Search scope (Model defined)
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Sorting and Joins for Salesperson Name
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $skipSorting = false;
        $rawFilters = $filters['filters'] ?? [];

        // Join Users if sorting or filtering by salesperson_name
        if ($sortField === 'salesperson_name' || isset($rawFilters['salesperson_name'])) {
            $query->leftJoin('users as salespersons', 'leads.salesperson_id', '=', 'salespersons.id')
                ->addSelect('salespersons.name as salesperson_name');
            if ($sortField === 'salesperson_name') {
                $query->orderBy('salespersons.name', $sortOrder);
                $skipSorting = true;
            }
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $rawFilters,
            [
                'aliases' => [
                    'email' => 'leads.email',
                    'phone_number' => 'leads.phone_number',
                    'contact_name' => 'leads.contact_person',
                    'opportunity' => 'leads.tasks_no',
                    'lead_id' => 'leads.lead_id',
                    'lead_type' => 'leads.lead_type',
                    'status' => 'leads.status',
                    'probability' => 'leads.probability',
                    'salesperson_name' => 'salespersons.name',
                ],
                'skip_sorting' => $skipSorting,
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            LeadResource::class,
            __('lead.lead_listed')
        );
    }

    /**
     * Create a new lead.
     */
    public function create(array $data): Lead
    {
        DB::beginTransaction();
        try {
            $leadIds = $this->generateLeadIds();
            $data['lead_id'] = $leadIds['lead_id'];
            $data['tasks_no'] = $leadIds['tasks_no'];
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();

            // If the creator is a partner, assign their partner_id if not already set
            if (empty($data['partner_id']) && Auth::user()->partner_id) {
                $data['partner_id'] = Auth::user()->partner_id;
            }

            $lead = Lead::create($data);
            DB::commit();
            return $lead->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Lead creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update lead.
     */
    public function update(Lead $lead, array $data): Lead
    {
        DB::beginTransaction();
        try {
            $data['updated_by'] = Auth::id();
            $lead->update($data);
            DB::commit();
            return $lead->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Lead update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete lead.
     */
    public function delete(Lead $lead): bool
    {
        return $lead->delete();
    }

    /**
     * Delete Multiple
     */
    public function deleteMultiple(array $ids): bool
    {
        if (empty($ids)) return false;
        return Lead::whereIn('id', $ids)->delete();
    }

    /**
     * Find lead by ID.
     */
    public function find(int $id): Lead
    {
        return Lead::findOrFail($id);
    }

    /**
     * Generate Unique Lead ID and Task No
     */
    private function generateLeadIds(): array
    {
        $datePart = now()->format('Y-md');
        $count = Lead::withTrashed()->whereDate('created_at', now()->toDateString())->count() + 1;

        return [
            'lead_id' => sprintf('LD-%s-%03d', $datePart, $count),
            'tasks_no' => sprintf('LD-TASK-%s-%03d', str_replace('-', '', $datePart), $count),
        ];
    }

    /**
     * Generic Convert Lead
     */
    public function convert(Lead $lead): Borrower|Partner
    {
        if ($lead->lead_type === 'Borrower') {
            return $this->convertToBorrower($lead);
        } elseif ($lead->lead_type === 'Partner') {
            return $this->convertToPartner($lead);
        }

        throw new \InvalidArgumentException('Invalid lead type for conversion: ' . $lead->lead_type);
    }

    /**
     * Convert Lead to Borrower
     */
    public function convertToBorrower(Lead $lead): Borrower
    {
        DB::beginTransaction();
        try {
            // Strict check as requested: contact_person, email, and phone_number must not be empty
            if (empty($lead->contact_person) || empty($lead->email) || empty($lead->phone_number)) {
                throw new \InvalidArgumentException('Contact person, email, and phone number are required for conversion.');
            }

            // Validation: Check if a borrower with this email already exists
            if ($lead->email && Borrower::where('email', $lead->email)->exists()) {
                throw new \InvalidArgumentException('A borrower with this email already exists.');
            }

            $firstName = $this->getFirstName($lead->contact_person);
            $lastName = $this->getLastName($lead->contact_person);
            $borrowerData = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $lead->email,
                'phone_number' => $lead->phone_number,
                'business_name' => $lead->legal_business_name ?: $lead->company_name,
                'business_ein' => $lead->business_ein,
                'business_address' => $lead->business_address,
                'country' => $lead->country,
                'business_city' => $lead->city,
                'business_state' => $lead->state,
                'business_postal_code' => $lead->zip_code,
                'business_phone' => $lead->business_phone,
                'business_entity_type' => $lead->entity_type,
                'business_industry_type' => $lead->industry_type,
                'business_start_date' => $lead->business_start_date,
                'duns_number' => $lead->duns_number,
                'state_file_number' => $lead->state_file_number,
                'last_4_ssn' => $lead->ssn_last_4,
                'notes' => $lead->requirement_details,
                'status' => 'active',
                'assign_id' => $lead->salesperson_id,
                'partner_id' => $lead->partner_id ?: (Auth::user()?->partner_id ?? null),
                'current_tier' => Tier::where('order', 1)->first()?->id,
            ];

            $borrower = Borrower::create($borrowerData);

            // Update lead conversion status
            $lead->update([
                'convert_to' => 'Borrower',
                'status' => 'Lead Completed',
            ]);

            // Create associated User if needed (check first_name, last_name, email, phone_number)
            if (!empty($firstName) && !empty($lastName) && !empty($lead->email) && !empty($lead->phone_number)) {
                $this->createBorrowerUser($borrower, $firstName, $lastName);
            }

            DB::commit();
            return $borrower;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Lead to Borrower conversion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create User for Borrower
     */
    private function createBorrowerUser(Borrower $borrower, string $firstName, string $lastName): void
    {
        $plainPassword = bin2hex(random_bytes(4));
        $userData = [
            'name' => $firstName . ' ' . $lastName,
            'email' => $borrower->email,
            'phone_number' => $borrower->phone_number,
            'user_type' => User::BORROWER,
            'password' => bcrypt($plainPassword),
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'status' => 'active',
        ];

        $user = User::create($userData);

        // Assign 'Borrower' role based on partner_id
        $role = Role::where('name', 'Borrower')
            ->where('partner_id', $borrower->partner_id)
            ->first();

        if ($role) {
            $user->assignRole($role);
        }
    }

    /**
     * Convert Lead to Partner
     */
    public function convertToPartner(Lead $lead): Partner
    {
        DB::beginTransaction();
        try {
            // Strict check as requested: contact_person, email, and phone_number must not be empty
            if (empty($lead->contact_person) || empty($lead->email) || empty($lead->phone_number)) {
                throw new \InvalidArgumentException('Contact person, email, and phone number are required for conversion.');
            }

            // Validation: Check if a partner with this email already exists
            if ($lead->email && Partner::where('email', $lead->email)->exists()) {
                throw new \InvalidArgumentException('A partner with this email already exists.');
            }

            $partnerData = [
                'full_name' => $lead->contact_person,
                'company_name' => $lead->legal_business_name ?: $lead->company_name,
                'email' => $lead->email,
                'phone_number' => $lead->phone_number,
                'contact_person' => $lead->contact_person,
                'contact_person_email' => $lead->email,
                'contact_person_phone_number' => $lead->phone_number,
                'partner_type' => 'Referral Partner', // Default mapping
                'status' => 'active',
                'country' => $lead->country,
                'state' => $lead->state,
                'city' => $lead->city,
                'postal_code' => $lead->zip_code,
                'address_line_1' => $lead->business_address,
                'tax_id' => $lead->business_ein,
                'website' => $lead->domain,
                'assign_to' => $lead->salesperson_id,
            ];

            $partner = Partner::create($partnerData);

            // Update lead conversion status
            $lead->update([
                'convert_to' => 'Partner',
                'status' => 'Lead Completed',
            ]);

            // Create associated User if needed (check contact_person, email, phone_number)
            if (!empty($lead->contact_person) && !empty($lead->email) && !empty($lead->phone_number)) {
                $this->createPartnerUser($partner);
            }

            DB::commit();
            return $partner;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Lead to Partner conversion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create User and Roles for Partner
     */
    private function createPartnerUser(Partner $partner): void
    {
        $plainPassword = bin2hex(random_bytes(4));
        $userData = [
            'name' => $partner->full_name,
            'email' => $partner->email,
            'phone_number' => $partner->phone_number,
            'user_type' => User::PARTNER,
            'password' => bcrypt($plainPassword),
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'partner_id' => $partner->id,
            'status' => 'active',
        ];

        $user = User::create($userData);

        // Create Default Roles and Assign Admin Role
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Admin Role
        $adminRole = Role::updateOrCreate(
            ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partner->id],
            [
                'code' => 'admin',
                'is_static' => false,
            ]
        );

        // Borrower Role
        Role::updateOrCreate(
            ['name' => 'Borrower', 'guard_name' => 'api', 'partner_id' => $partner->id],
            [
                'code' => 'borrower',
                'is_static' => false,
            ]
        );

        $user->assignRole($adminRole);

        // Sync permissions (only non-host permissions for normal partners)
        $permissions = Permission::where('guard_name', 'api')->where('is_host', 0)->get();
        if ($permissions->isNotEmpty()) {
            $user->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function getFirstName(?string $fullName): string
    {
        if (!$fullName) return '';
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    private function getLastName(?string $fullName): string
    {
        if (!$fullName) return '';
        $parts = explode(' ', trim($fullName));
        if (count($parts) > 1) {
            array_shift($parts);
            return implode(' ', $parts);
        }
        return '';
    }
}
