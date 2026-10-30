<?php

namespace App\Services\FCH\Common\Lead;

use App\Services\FCH\Common\Notification\Notifier;
use App\Http\Resources\FCH\Lead\LeadResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationRequest;
use App\Exceptions\LeadConvertedException;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Services\FCH\Partner\PartnerService;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Mail\Lead\LeadNotificationMail;
use App\Mail\Lead\LeadConvertedOnBehalfMail;

class LeadService
{
    use PaginatesApi;

    public function __construct(
        protected BorrowerService $borrowerService,
        protected PartnerService $partnerService,
        protected \App\Services\FCH\Common\ActivityLog\ActivityLogService $activityLogService,
    ) {
    }

    /**
     * List leads with pagination and filtering.
     */
    public function list(array $filters)
    {
        $authUser = Auth::user();
        $query = Lead::query()
            ->with(['salesperson', 'partner'])
            ->visibleTo($authUser)
            ->select('leads.*');

        // Top-level Dropdown Filters (Robust Normalization)
        $leadType = is_array($filters['lead_type'] ?? null) ? ($filters['lead_type']['value'] ?? null) : ($filters['lead_type'] ?? null);
        $statusValue = is_array($filters['status'] ?? null) ? ($filters['status']['value'] ?? null) : ($filters['status'] ?? null);
        $salespersonId = is_array($filters['salesperson_id'] ?? null) ? ($filters['salesperson_id']['value'] ?? null) : ($filters['salesperson_id'] ?? null);

        // The host's Partner filter; within visibleTo, so a partner naming
        // another partner's id only narrows its own list to nothing.
        if (!empty($filters['partner_id'])) {
            $query->where('leads.partner_id', $filters['partner_id']);
        }

        // Snapshot before the search, dropdown and column filters, so the summary
        // cards show true totals instead of recounting whatever the table is
        // currently narrowed to (as the Partners and Clients lists do).
        $statsQuery = clone $query;

        // Apply Dropdown Filters - Strictly skip if null or empty string to show "All" data
        if ($leadType !== null && $leadType !== '') {
            $query->where('leads.lead_type', $leadType);
        }

        if ($statusValue !== null && $statusValue !== '') {
            $query->where('leads.status', $statusValue);
        }

        if ($salespersonId !== null && $salespersonId !== '') {
            $query->where('leads.salesperson_id', $salespersonId);
        }

        // Apply Global Search
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Sorting configuration
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $skipSorting = false;

        // Manual column filters handling
        $rawFilters = $filters['filters'] ?? [];
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true) ?? [];
        }

        // 1. Filter by Salesperson Name
        if (isset($rawFilters['salesperson_name'])) {
            $constraints = $rawFilters['salesperson_name']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('salesperson', function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['salesperson_name']);
        }

        // 2. Filter by Partner Name
        if (isset($rawFilters['partner_name'])) {
            $constraints = $rawFilters['partner_name']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('partner', function ($q) use ($value) {
                        $q->where(fn ($p) => $p->where('company_name', 'like', "%{$value}%")
                            ->orWhere('full_name', 'like', "%{$value}%"));
                    });
                }
            }
            unset($rawFilters['partner_name']);
        }

        // Handle Relation Sorting
        if ($sortField === 'salesperson_name') {
            $query->orderBy(
                User::select('name')
                    ->whereColumn('users.id', 'leads.salesperson_id')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
        } elseif ($sortField === 'partner_name') {
            $query->orderBy(
                Partner::select('company_name')
                    ->whereColumn('partners.id', 'leads.partner_id')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
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
                    'contact_person' => 'leads.contact_person',
                    'opportunity' => 'leads.tasks_no',
                    'lead_id' => 'leads.lead_id',
                    'lead_type' => 'leads.lead_type',
                    'status' => 'leads.status',
                    'probability' => 'leads.probability',
                ],
                'skip_sorting' => $skipSorting,
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $statistics = $this->getStats($statsQuery);

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            LeadResource::class,
            __('lead.lead_listed'),
            200,
            ['statistics' => $statistics]
        );
    }

    /**
     * Create a new lead from Lead Details tab.
     */
    public function storeDetails(array $data): Lead
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

            // Enforce referral_partner_id null when lead_source is not Referral Partner
            if (isset($data['lead_source']) && $data['lead_source'] !== Lead::LEAD_SOURCE_REFERRAL_PARTNER) {
                $data['referral_partner_id'] = null;
            }

            $lead = Lead::create($data);

            // Send Emails based on Lead Source
            $isWebsiteLead = ($lead->lead_source === Lead::LEAD_SOURCE_WEBSITE);

            if (($isWebsiteLead && !Auth::check()) || (Auth::user()->partner->is_host)) {
                // If it's a website lead, send to host partner (standard website logic)
                // Catch \Throwable (not \Exception) so a mail failure - e.g. a \TypeError
                // from a misconfigured mailer - never blocks lead creation.
                try {
                    $hostPartner = Partner::where('is_host', true)->first();
                    if ($hostPartner) {
                        $details = [
                            'type' => $isWebsiteLead ? 'website' : 'manual',
                            'is_pre_qualification' => false,
                        ];
                        app(Notifier::class)->send('lead.created', $hostPartner, $this->leadBellData($lead),
                            mail: $hostPartner->email
                                ? (new LeadNotificationMail($lead, $details))->to($hostPartner->email)
                                : null);
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to send website-style lead email notification from admin', ['error' => $e->getMessage()]);
                }
            } else {
                // Otherwise, it's a manual/admin-created lead from other sources
                $recipients = [
                    Auth::user()->email,
                ];

                if (!empty($recipients)) {
                    try {
                        $details = [
                            'type' => 'manual',
                            'is_pre_qualification' => false,
                        ];
                        // The person who created it, as before.
                        app(Notifier::class)->send('lead.created', Auth::user(), $this->leadBellData($lead),
                            mail: (new LeadNotificationMail($lead, $details))->to($recipients));
                    } catch (\Throwable $e) {
                        Log::error('Failed to send manual lead email notification', ['error' => $e->getMessage()]);
                    }
                }
            }

            // Log Lead Creation
            $this->activityLogService->logModelEvent($lead, \App\Models\ActivityLog\ActivityLog::LOG_TYPE_LEAD_CREATE);

            DB::commit();
            return $lead->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Lead store details failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update lead from Lead Details tab.
     */
    public function updateDetails(Lead $lead, array $data): Lead
    {
        DB::beginTransaction();
        try {
            $data['updated_by'] = Auth::id();
            if (!empty($data['business_start_date'])) {
                try {
                    $data['business_start_date'] = Carbon::parse(str_replace('-', '/', $data['business_start_date']))->format('Y-m-d');
                } catch (\Exception $e) {
                    unset($data['business_start_date']);
                }
            }

            // Enforce referral_partner_id null when lead_source is not Referral Partner
            if (isset($data['lead_source']) && $data['lead_source'] !== Lead::LEAD_SOURCE_REFERRAL_PARTNER) {
                $data['referral_partner_id'] = null;
            }

            $lead->update($data);

            $changes = $lead->getChanges();
            unset($changes['updated_at'], $changes['updated_by']);

            if (!empty($changes)) {
                $changedFields = [];
                $extraProps = [];
                foreach ($changes as $field => $newValue) {
                    // The Lead Owner, by name rather than user id
                    if ($field === 'salesperson_id') {
                        $owner = $this->activityLogService->describeOwnerChange(
                            __('activity_log.owner_label_lead'),
                            $lead->getPrevious()['salesperson_id'] ?? null,
                            $newValue
                        );
                        $changedFields[] = $owner['sentence'];
                        $extraProps['changes']['salesperson_id'] = $owner['change'];
                        continue;
                    }

                    // An EIN is recorded as changed, never repeated
                    if (\App\Models\ActivityLog\ActivityLog::isSensitiveField($field)) {
                        $changedFields[] = __('activity_log.sensitive_field_updated', ['field' => \App\Models\ActivityLog\ActivityLog::fieldLabel($field)]);
                        continue;
                    }

                    $name = ucwords(str_replace('_', ' ', $field));
                    $val = (string) $newValue;
                    $val = $val === '' ? 'empty' : $val;
                    $changedFields[] = "{$name} was updated to {$val}";
                }
                $fieldsList = implode(', ', $changedFields);
                $customDescription = "{$fieldsList}.";

                // Only the Lead Owner changed: an entry saying just that
                $customSubject = array_keys($changes) === ['salesperson_id'] ? __('activity_log.owner_subject_lead') : null;

                $this->activityLogService->logModelEvent(
                    $lead,
                    \App\Models\ActivityLog\ActivityLog::LOG_TYPE_LEAD_DETAILS_UPDATE,
                    $extraProps,
                    $customSubject,
                    $customDescription
                );
            }

            DB::commit();
            return $lead->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Lead update details failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update lead from Contact Info tab.
     */
    public function updateContactInfo(Lead $lead, array $data): Lead
    {
        DB::beginTransaction();
        try {
            $data['updated_by'] = Auth::id();
            if (!empty($data['business_start_date'])) {
                try {
                    $data['business_start_date'] = Carbon::parse(str_replace('-', '/', $data['business_start_date']))->format('Y-m-d');
                } catch (\Exception $e) {
                    unset($data['business_start_date']);
                }
            }
            $lead->update($data);

            $changes = $lead->getChanges();
            unset($changes['updated_at'], $changes['updated_by']);

            if (!empty($changes)) {
                $isEmailOnly = (count($changes) === 1 && isset($changes['email']));
                $logType = $isEmailOnly ? \App\Models\ActivityLog\ActivityLog::LOG_TYPE_EMAIL_UPDATE : \App\Models\ActivityLog\ActivityLog::LOG_TYPE_LEAD_CONTACT_UPDATE;

                $extraProps = [];
                if (isset($changes['email'])) {
                    $extraProps['new_email'] = $changes['email'];
                }

                $changedFields = [];
                foreach ($changes as $field => $newValue) {
                    // An EIN is recorded as changed, never repeated
                    if (\App\Models\ActivityLog\ActivityLog::isSensitiveField($field)) {
                        $changedFields[] = __('activity_log.sensitive_field_updated', ['field' => \App\Models\ActivityLog\ActivityLog::fieldLabel($field)]);
                        continue;
                    }

                    $name = ucwords(str_replace('_', ' ', $field));
                    $val = (string) $newValue;
                    $val = $val === '' ? 'empty' : $val;
                    $changedFields[] = "{$name} was updated to {$val}";
                }
                $fieldsList = implode(', ', $changedFields);
                $customDescription = $isEmailOnly ? null : "{$fieldsList}.";

                $this->activityLogService->logModelEvent(
                    $lead,
                    $logType,
                    $extraProps,
                    null,
                    $customDescription
                );
            }

            DB::commit();
            return $lead->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Lead update contact info failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete lead.
     */
    public function delete(Lead $lead): bool
    {
        $this->activityLogService->logModelEvent($lead, \App\Models\ActivityLog\ActivityLog::LOG_TYPE_LEAD_DELETE);
        return $lead->delete();
    }

    /**
     * Delete several leads at once, each logged as a single delete is. Ids of
     * leads the user may not see are skipped, and a selection holding a
     * converted lead is refused whole. Returns how many were deleted.
     */
    public function deleteMultiple(array $ids, User $user): int
    {
        if (empty($ids)) {
            return 0;
        }

        $leads = Lead::visibleTo($user)->whereIn('id', $ids)->get();

        // A lead already converted to a borrower or partner is kept. Refuse the
        // whole selection and name each one; nothing is deleted or changed.
        $converted = $leads->filter(fn (Lead $lead) => !empty($lead->convert_to));
        if ($converted->isNotEmpty()) {
            throw new LeadConvertedException(
                $leads->count() === 1
                    ? __('lead.delete_blocked_converted')
                    : __('lead.delete_blocked_converted_named', ['names' => $converted->map(fn (Lead $l) => $this->leadName($l))->implode(', ')]),
                $converted->map(fn (Lead $l) => ['id' => $l->id, 'name' => $this->leadName($l)])->values()->all()
            );
        }

        return DB::transaction(function () use ($leads) {
            foreach ($leads as $lead) {
                $this->delete($lead);
            }

            return $leads->count();
        });
    }

    /** The name a lead is shown by: its contact person, else its company. */
    private function leadName(Lead $lead): string
    {
        return $lead->contact_person ?: ($lead->company_name ?: (string) $lead->lead_id);
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
        if ($lead->lead_type === Lead::BORROWER_TYPE_LEAD) {
            return $this->convertToBorrower($lead, $data);
        } elseif ($lead->lead_type === Lead::PARTNER_TYPE_LEAD) {
            return $this->convertToPartner($lead, $data);
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

            $enrollmentData = is_array($data['enrollment'] ?? null) ? $data['enrollment'] : [];
            $enrollmentData = array_merge([
                'plan_id' => $data['plan_id'] ?? ($enrollmentData['plan_id'] ?? null),
                'enrollment_type' => $data['enrollment_type'] ?? ($enrollmentData['enrollment_type'] ?? null),
                'amount' => $data['enrollment_amount'] ?? ($data['amount'] ?? ($enrollmentData['amount'] ?? null)),
                'down_payment' => $data['installment_down_payment'] ?? ($data['down_payment'] ?? ($enrollmentData['down_payment'] ?? null)),
                'months' => $data['installment_months'] ?? ($data['months'] ?? ($enrollmentData['months'] ?? null)),
                'monthly_amount' => $data['installment_monthly_amount'] ?? ($data['monthly_amount'] ?? ($enrollmentData['monthly_amount'] ?? null)),
                'remaining_balance' => $data['installment_remaining_balance'] ?? ($data['remaining_balance'] ?? ($enrollmentData['remaining_balance'] ?? null)),
            ], $enrollmentData);

            $borrowerData = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $lead->email,
                'phone_number' => $lead->phone_number,
                'business_name' => $lead->legal_business_name ?: $lead->company_name,
                'business_ein' => $lead->business_ein,
                'business_address' => $lead->business_address,
                'business_email' => $lead->email,
                'business_website' => $lead->domain,
                'business_country' => $lead->country,
                'business_country_code' => Lead::DEFAULT_COUNTRY_CODE,
                'business_city' => $lead->city,
                'business_state' => $lead->state,
                'business_postal_code' => $lead->zip_code,
                'business_phone' => $lead->business_phone,
                'business_entity_type' => $lead->entity_type,
                'business_entity_type_note' => $lead->entity_type_note,
                'business_industry_type' => $lead->industry_type,
                'business_industry_type_note' => $lead->industry_type_note,
                'business_start_date' => $lead->business_start_date,
                'duns_number' => $lead->duns_number,
                'state_file_number' => $lead->state_file_number,
                'ssn' => $lead->ssn,
                'notes' => $lead->requirement_details,
                'status' => Borrower::STATUS_ONBOARDING,
                'country' => $lead->country,
                'country_code' => Lead::DEFAULT_COUNTRY_CODE,
                'state' => $lead->state,
                'city' => $lead->city,
                'postal_code' => $lead->zip_code,
                'home_address' => $lead->business_address,
                'home_phone' => $lead->business_phone,
                'assign_id' => $lead->salesperson_id,
                'partner_id' => $this->borrowerPartnerId($lead),
                'plan_id' => $data['plan_id'] ?? null,
                'enrollment_payment' => $data['enrollment_amount'] ?? ($data['amount'] ?? null),
                'is_free_trail' => $data['is_free_trail'] ?? null,
                'free_trail_end_date' => $data['free_trail_end_date'] ?? (
                    (($data['is_free_trail'] ?? null) == 1 || ($data['is_free_trail'] ?? null) === true || ($data['is_free_trail'] ?? null) === 'true')
                    ? now()->addDays(30)->toDateString()
                    : null
                ),
                'installment_down_payment' => $data['installment_down_payment'] ?? ($data['down_payment'] ?? null),
                'installment_months' => $data['installment_months'] ?? ($data['months'] ?? null),
                'installment_monthly_amount' => $data['installment_monthly_amount'] ?? ($data['monthly_amount'] ?? null),
                'installment_remaining_balance' => $data['installment_remaining_balance'] ?? ($data['remaining_balance'] ?? null),
                'enrollment' => $enrollmentData,
            ];

            $result = $this->borrowerService->store($borrowerData);
            $borrower = $result['borrower'];

            $lead->update([
                'convert_to' => Lead::BORROWER_TYPE_LEAD,
                'status' => Lead::STATUS_LEAD_COMPLETED,
            ]);

            $preQualification = PreQualificationRequest::where('lead_id', $lead->id)->first();
            if ($preQualification) {
                $preQualification->update([
                    'partner_id' => $borrower->partner_id,
                    'borrower_id' => $borrower->id,
                ]);
            }

            DB::commit();

            if ($lead->convertsOnBehalfOfPartnerFor(Auth::user())) {
                $this->notifyConvertedOnBehalf($lead, $borrower);
            }

            return $borrower;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Lead to Borrower conversion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }


    /**
     * Convert Lead to Partner
     */
    public function convertToPartner(Lead $lead, array $data = []): Partner
    {
        DB::beginTransaction();
        try {
            if (empty($lead->contact_person) || empty($lead->email) || empty($lead->phone_number)) {
                throw new \InvalidArgumentException('Contact person, email, and phone number are required for conversion.');
            }

            if ($lead->email && Partner::where('email', $lead->email)->exists()) {
                throw new \InvalidArgumentException('A partner with this email already exists.');
            }

            $enrollmentData = is_array($data['enrollment'] ?? null) ? $data['enrollment'] : [];
            $enrollmentData = array_merge([
                'plan_id' => $data['plan_id'] ?? ($enrollmentData['plan_id'] ?? null),
                'partner_type' => $data['partner_type'] ?? ($enrollmentData['partner_type'] ?? 'Referral Partner'),
                'enrollment_type' => $data['enrollment_type'] ?? ($enrollmentData['enrollment_type'] ?? 'Monthly Platform Fee'),
                'amount' => $data['enrollment_amount'] ?? ($data['amount'] ?? ($enrollmentData['amount'] ?? null)),
                'monthly_platform_fee' => $data['monthly_platform_fee'] ?? ($enrollmentData['monthly_platform_fee'] ?? null),
                'down_payment' => $data['installment_down_payment'] ?? ($data['down_payment'] ?? ($enrollmentData['down_payment'] ?? null)),
                'months' => $data['installment_months'] ?? ($data['months'] ?? ($enrollmentData['months'] ?? null)),
                'monthly_amount' => $data['installment_monthly_amount'] ?? ($data['monthly_amount'] ?? ($enrollmentData['monthly_amount'] ?? null)),
                'remaining_balance' => $data['installment_remaining_balance'] ?? ($data['remaining_balance'] ?? ($enrollmentData['remaining_balance'] ?? null)),
            ], $enrollmentData);

            // Map Lead data to Partner storage format
            $partnerData = [
                'full_name' => $lead->contact_person,
                'company_name' => $lead->legal_business_name ?: $lead->company_name,
                'email' => $lead->email,
                'phone_number' => $lead->phone_number,

                'contact_person' => $lead->contact_person,
                'contact_person_email' => $lead->email,
                'contact_person_phone_number' => $lead->phone_number,
                'contact_person_relation' => $lead->contact_person_relation ?? 'Primary',

                // Conversion Options from payload
                'partner_type' => $data['partner_type'] ?? 'Referral Partner',
                'enrollment_type' => $data['enrollment_type'] ?? 'Monthly Platform Fee',
                'enrollment_amount' => $data['enrollment_amount'] ?? ($data['amount'] ?? null),
                'monthly_platform_fee' => $data['monthly_platform_fee'] ?? null,

                'status' => Partner::STATUS_ONBOARDING,
                'joining_date' => now(),

                // Address & Business Info
                'country' => $lead->country,
                'state' => $lead->state,
                'city' => $lead->city,
                'postal_code' => $lead->zip_code, // Store expects postal_code or aliased
                'zip_code' => $lead->zip_code,    // Backward compatibility with Request
                'address_line_1' => $lead->business_address,
                'tax_id' => $lead->business_ein,
                'website' => $lead->domain,
                'assign_to' => $lead->salesperson_id,
                'plan_id' => $data['plan_id'] ?? null,
                'is_free_trail' => $data['is_free_trail'] ?? null,
                'free_trail_end_date' => $data['free_trail_end_date'] ?? (
                    (($data['is_free_trail'] ?? null) == 1 || ($data['is_free_trail'] ?? null) === true || ($data['is_free_trail'] ?? null) === 'true')
                    ? now()->addDays(30)->toDateString()
                    : null
                ),
                'installment_down_payment' => $data['installment_down_payment'] ?? ($data['down_payment'] ?? null),
                'installment_months' => $data['installment_months'] ?? ($data['months'] ?? null),
                'installment_monthly_amount' => $data['installment_monthly_amount'] ?? ($data['monthly_amount'] ?? null),
                'installment_remaining_balance' => $data['installment_remaining_balance'] ?? ($data['remaining_balance'] ?? null),
                'enrollment' => $enrollmentData,
            ];

            // Trigger robust Partner storage logic
            $result = $this->partnerService->store($partnerData);
            $partner = $result['partner'];

            $lead->update([
                'convert_to' => Lead::PARTNER_TYPE_LEAD,
                'status' => Lead::STATUS_LEAD_COMPLETED,
            ]);

            DB::commit();
            Log::info('Lead successfully converted to Partner', [
                'lead_id' => $lead->id,
                'partner_id' => $partner->id
            ]);

            return $partner;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Lead to Partner conversion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }


    /**
     * The partner a converted lead's borrower is filed under: always the
     * lead's own partner - the host converting another partner's lead does so
     * on that partner's behalf. A lead with no partner goes to the converter's.
     */
    private function borrowerPartnerId(Lead $lead): ?int
    {
        return $lead->partner_id ?: (Auth::user()?->partner_id ?? null);
    }

    /**
     * Tell the lead's partner - in its bell and by email from the host - that
     * the host converted its lead to its borrower. Failures are logged, never
     * thrown: a notice must not undo a conversion already committed.
     */
    private function notifyConvertedOnBehalf(Lead $lead, Borrower $borrower): void
    {
        try {
            $partner = $lead->partner;
            $host = Auth::user()->partner;

            app(Notifier::class)->send('partner.lead.converted_on_behalf', $partner, [
                'name' => $lead->contact_person ?: $lead->company_name,
                'host' => $host->company_name ?: $host->full_name,
                'borrower_id' => $borrower->id,
            ], mail: $partner->email
                ? (new LeadConvertedOnBehalfMail($lead, $borrower, $host, $partner))->to($partner->email)
                : null);
        } catch (Throwable $e) {
            Log::error('Failed to notify the partner of a lead converted on its behalf', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Placeholders for the lead.created bell. */
    private function leadBellData(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->contact_person ?: $lead->company_name,
            'source' => strtolower((string) $lead->lead_source),
        ];
    }

    private function getFirstName(?string $fullName): string
    {
        if (!$fullName)
            return '';
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    private function getLastName(?string $fullName): string
    {
        if (!$fullName)
            return '';
        $parts = explode(' ', trim($fullName));
        if (count($parts) > 1) {
            array_shift($parts);
            return implode(' ', $parts);
        }
        return '';
    }

    /**
     * Get Leads Statistics based on a query.
     */
    public function getStats($query = null): array
    {
        if (!$query) {
            $query = Lead::query()->visibleTo(Auth::user());
        }

        $baseQuery = clone $query;
        $totalLeads = (clone $baseQuery)->count();
        $newLeads = (clone $baseQuery)->where('status', Lead::STATUS_NEW_LEAD)->count();
        $declinedLeads = (clone $baseQuery)->where('status', Lead::STATUS_LEAD_DECLINED)->count();

        $qualifiedStatuses = Lead::getQualifiedStatuses();
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
