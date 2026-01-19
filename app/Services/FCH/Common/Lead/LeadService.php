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
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Services\FCH\Partner\PartnerService;
use App\Services\FCH\InvoiceService;
use App\Models\Invoice\Invoice;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class LeadService
{
    use PaginatesApi;

    public function __construct(
        protected BorrowerService $borrowerService,
        protected PartnerService $partnerService,
        protected InvoiceService $invoiceService
    ) {}

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
    public function convert(Lead $lead, array $data = []): Borrower|Partner
    {
        if ($lead->lead_type === 'Borrower') {
            return $this->convertToBorrower($lead, $data);
        } elseif ($lead->lead_type === 'Partner') {
            return $this->convertToPartner($lead);
        }

        throw new \InvalidArgumentException('Invalid lead type for conversion: ' . $lead->lead_type);
    }

    /**
     * Convert Lead to Borrower
     */
    public function convertToBorrower(Lead $lead, array $data = []): Borrower
    {
        DB::beginTransaction();
        try {
            if (empty($lead->contact_person) || empty($lead->email) || empty($lead->phone_number)) {
                throw new \InvalidArgumentException('Contact person, email, and phone number are required for conversion.');
            }

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
                'status' => 'OnBoarding',
                'enrollment_type' => $data['enrollment_type'] ?? null,
                'assign_id' => $lead->salesperson_id,
                'partner_id' => $lead->partner_id ?: (Auth::user()?->partner_id ?? null),
            ];

            Log::info('Borrower data before store: ' . json_encode($borrowerData));
            $result = $this->borrowerService->store($borrowerData);
            Log::info('Borrower data after store: ' . json_encode($result));
            $borrower = $result['borrower'];
            $user = $result['user'];
            $plainPassword = '12345';

            // Automatic Invoice Creation based on enrollment type
            if (!empty($data['enrollment_type'])) {
                Log::info('enrollment type: ' . json_encode($data['enrollment_type']));
                $enrollmentConfigs = config('enrollment.types');
                $matchedType = null;
                foreach ($enrollmentConfigs as $key => $config) {
                    if ($config['label'] === $data['enrollment_type']) {
                        $matchedType = $config;
                        break;
                    }
                }
                Log::info('matchedType: ' . $matchedType);
                if ($matchedType) {
                    $this->invoiceService->create([
                        'partner_id' => $borrower->partner_id,
                        'borrower_id' => $borrower->id,
                        'entity_type' => Borrower::class,
                        'entity_id' => $borrower->id,
                        'customer_name' => $borrower->full_name,
                        'email' => $borrower->email,
                        'country' => $borrower->country,
                        'payment_amount' => $matchedType['amount'],
                        'status' => Invoice::STATUS_UNPAID,
                        'cycle' => $data['enrollment_type'] === '6 Month Payment' ? Invoice::CYCLE_MONTHLY : Invoice::CYCLE_ONE_TIME,
                    ]);
                    Log::info('Invoice created: ' . json_encode($matchedType));
                }
            }

            $lead->update([
                'convert_to' => 'Borrower',
                'status' => 'Lead Completed',
            ]);

            if ($user && $plainPassword) {
                $hostPartner = Partner::where('is_host', 1)->first();
                $mailData = [
                    'user' => $user,
                    'plain_password' => $plainPassword,
                    'business_info' => [
                        'company_name' => $hostPartner->company_name ?? config('app.name'),
                        'company_logo' => $hostPartner->company_logo ?? 'logo.png',
                        'email' => $hostPartner->email ?? config('mail.from.address'),
                        'phone_number' => $hostPartner->phone_number ?? '',
                        'address_line_1' => $hostPartner->address_line_1 ?? '',
                    ]
                ];
                Mail::to($lead->email)->send(new BorrowerAccountCreatedMail($mailData));
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
     * Convert Lead to Partner
     */
    public function convertToPartner(Lead $lead): Partner
    {
        DB::beginTransaction();
        try {
            if (empty($lead->contact_person) || empty($lead->email) || empty($lead->phone_number)) {
                throw new \InvalidArgumentException('Contact person, email, and phone number are required for conversion.');
            }

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
                'partner_type' => 'Referral Partner',
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

            $result = $this->partnerService->store($partnerData);
            $partner = $result['partner'];
            $user = $result['user'];
            $plainPassword = $result['plain_password'];

            $lead->update([
                'convert_to' => 'Partner',
                'status' => 'Lead Completed',
            ]);

            // Send email to lead
            if ($user && $plainPassword) {
                $hostPartner = Partner::where('is_host', 1)->first();
                $mailData = [
                    'user' => $user,
                    'plain_password' => $plainPassword,
                    'business_info' => [
                        'company_name' => $hostPartner->company_name ?? config('app.name'),
                        'company_logo' => $hostPartner->company_logo ?? 'logo.png',
                        'email' => $hostPartner->email ?? config('mail.from.address'),
                        'phone_number' => $hostPartner->phone_number ?? '',
                        'address_line_1' => $hostPartner->address_line_1 ?? '',
                    ]
                ];
                Mail::to($lead->email)->send(new BorrowerAccountCreatedMail($mailData));
            }

            DB::commit();
            return $partner;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Lead to Partner conversion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
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

    /**
     * Get Leads Statistics
     */
    public function getStats(): array
    {
        $authUser = Auth::user();
        $query = Lead::query();

        if ($authUser && $authUser->partner) {
            $query->where('partner_id', $authUser->partner_id);
        }

        $baseQuery = clone $query;
        $totalLeads = (clone $baseQuery)->count();
        $newLeads = (clone $baseQuery)->where('status', 'New Lead')->count();
        $declinedLeads = (clone $baseQuery)->where('status', 'Lead Declined')->count();
        $qualifiedStatuses = [
            'Lead Identified',
            'Meeting Arranged',
            'Application Allowed',
            'Negotiation',
            'Lead Completed'
        ];
        $qualifiedLeads = (clone $baseQuery)->whereIn('status', $qualifiedStatuses)->count();

        $qualifiedRate = $totalLeads > 0 ? round(($qualifiedLeads / $totalLeads) * 100, 1) : 0;
        $declinedRate = $totalLeads > 0 ? round(($declinedLeads / $totalLeads) * 100, 1) : 0;

        return [
            'total_leads' => $totalLeads,
            'new_leads' => $newLeads,
            'qualified_rate' => $qualifiedRate,
            'declined_rate' => $declinedRate,
        ];
    }
}
