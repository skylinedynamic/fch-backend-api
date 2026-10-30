<?php

namespace App\Services\FCH\Common\Borrower;

use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Mail\Mailable;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use Illuminate\Http\UploadedFile;
use App\Models\CreditBuilder\Tier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\Common\Invoice\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Borrower\BorrowerDocument;
use App\Models\ActivityLog\ActivityLog;
use App\Services\Global\Datatable\QueryFilter;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Http\Resources\FCH\Borrower\BorrowerResource;
use App\Services\FCH\Subscription\EnrollmentService;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Validation\ValidationException;



class BorrowerService
{
    /** The numbers a client's details carry masked, which revealField() gives in full. */
    public const REVEALABLE_FIELDS = ['ssn', 'business_ein', 'credit_profile_ssn'];
    use PaginatesApi, UploadsFiles;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected SubscriptionService $subscriptionService,
        protected EnrollmentService $enrollmentService,
        protected \App\Services\FCH\Common\ActivityLog\ActivityLogService $activityLogService,
        protected \App\Services\Global\Account\EmailChangeService $emailChange,
    ) {
    }

    /**
     * Send a borrower email using the partner's active mail config.
     */
    /**
     * @param mixed $recipient Who gets the bell (the borrower's login user)
     */
    private function sendBorrowerMail(string $type, mixed $recipient, string $email, Mailable $mailable, int|null $partnerId, array $bellData = []): void
    {
        try {
            app(Notifier::class)->send($type, $recipient, $bellData, mail: $mailable->to($email));
        } catch (\Exception $e) {
            Log::error('Borrower email failed', [
                'email' => $email,
                'partner_id' => $partnerId,
                'error' => $e->getMessage(),
            ]);
        }
    }



    /**
     * List borrowers with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        $query = Borrower::query()
            ->with(['user:id,entity_type,entity_id', 'partner:id,full_name', 'assignedUser:id,name', 'tier:id,name', 'latestSubscription', 'bankAccounts:id,borrower_id,bank_name,account_open_date'])
            ->select('borrowers.*');

        // A borrower reaching this list sees only their own record.
        if ($authUser && $authUser->entity_type === Borrower::class) {
            $query->visibleTo($authUser);
        }

        // Filter based on authenticated user's partner and host status
        if ($authUser && $authUser->partner && $authUser->entity_type !== Borrower::class) {
            $partnerId = $filters['partner_id']['value'] ?? ($filters['partner_id'] ?? null);
            if ($authUser->partner->is_host == 1) {
                // If user is a host, filter by partner_id if provided, otherwise show all
                if ($partnerId !== null && $partnerId !== '') {
                    $query->where('borrowers.partner_id', $partnerId);
                }
            } else {
                // If user is not a host, only show borrowers from their own partner
                $query->where('borrowers.partner_id', $authUser->partner_id);
            }
        }

        // Snapshot the query scoped only by partner authorization (before search/status/column
        // filters are applied) so the summary cards always reflect true totals for the partner,
        // not just the rows matching whatever search/filter the user currently has active.
        $statsQuery = clone $query;

        // Archived borrowers are not active business, so they never count toward
        // the summary cards - whatever the list below is currently showing.
        $statsQuery->notArchived();

        // Filter by current_tier (supports ID or Name)
        $currentTier = $filters['current_tier']['value'] ?? ($filters['current_tier'] ?? null);
        if ($currentTier !== null && $currentTier !== '') {
            if (is_numeric($currentTier)) {
                $query->where('borrowers.current_tier', $currentTier);
            } else {
                $query->whereHas('tier', function ($q) use ($currentTier) {
                    $q->where('name', 'like', "%{$currentTier}%");
                });
            }
        }

        // Filter by assign_id
        $assignId = $filters['assigned_user_id']['value'] ?? ($filters['assigned_user_id'] ?? ($filters['assign_id']['value'] ?? ($filters['assign_id'] ?? null)));
        if ($assignId !== null && $assignId !== '') {
            $query->where('borrowers.assign_id', $assignId);
        }

        // Filter by status
        $status = $filters['status']['value'] ?? ($filters['status'] ?? null);
        if ($status !== null && $status !== '') {
            if (is_array($status)) {
                $query->whereIn('borrowers.status', $status);
            } else {
                $query->where('borrowers.status', $status);
            }
        }

        // Filter by Free Plan / Regular plan (is_free_trail)
        $isFreeTrail = $filters['is_free_trail']['value'] ?? ($filters['is_free_trail'] ?? null);
        if ($isFreeTrail !== null && $isFreeTrail !== '') {
            $query->where('borrowers.is_free_trail', filter_var($isFreeTrail, FILTER_VALIDATE_BOOLEAN));
        }

        // Apply Search
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // 🗄 Archived borrowers leave the working list but stay findable. A search
        // reaches them by default; `archived` narrows to them, `archived=all`
        // ignores the distinction entirely.
        // '' and null both mean "no archive filter chosen" - the UI sends an empty
        // string, not null, so both have to be treated the same or a search would
        // never reach archived records.
        $archived = $filters['archived'] ?? null;
        $archived = ($archived === '' ? null : $archived);

        if ($archived === null && !empty($filters['search'])) {
            $archived = Borrower::ARCHIVE_FILTER_ALL;
        }

        $query->archiveFilter($archived);

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Manual column filters handling
        $rawFilters = $filters['filters'] ?? [];
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true) ?? [];
        }

        // 1. Filter by Partner Name
        if (isset($rawFilters['partner_name'])) {
            $constraints = $rawFilters['partner_name']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('partner', function ($q) use ($value) {
                        $q->where('full_name', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['partner_name']);
        }

        // 2. Filter by Assigned User Name
        if (isset($rawFilters['assign_name'])) {
            $constraints = $rawFilters['assign_name']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('assignedUser', function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['assign_name']);
        }

        // 3. Filter by Current Tier Name
        if (isset($rawFilters['current_tier_name'])) {
            $constraints = $rawFilters['current_tier_name']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('tier', function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['current_tier_name']);
        }

        // 4. Filter by Status
        if (isset($rawFilters['status'])) {
            $constraints = $rawFilters['status']['constraints'] ?? [];
            if (!empty($constraints)) {
                $query->where(function ($q) use ($constraints) {
                    foreach ($constraints as $constraint) {
                        $value = $constraint['value'] ?? null;
                        if ($value !== null && $value !== '') {
                            if (is_array($value)) {
                                $q->orWhereIn('borrowers.status', $value);
                            } else {
                                $q->orWhere('borrowers.status', $value);
                            }
                        }
                    }
                });
            } else {
                $value = $rawFilters['status']['value'] ?? null;
                if ($value !== null && $value !== '') {
                    if (is_array($value)) {
                        $query->whereIn('borrowers.status', $value);
                    } else {
                        $query->where('borrowers.status', $value);
                    }
                }
            }
            unset($rawFilters['status']);
        }

        // Handle Relation Sorting
        $skipSorting = false;
        if ($sortField === 'assign_name') {
            $query->orderBy(
                User::select('name')
                    ->whereColumn('users.id', 'borrowers.assign_id')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
        } elseif ($sortField === 'partner_name') {
            $query->orderBy(
                Partner::select('full_name')
                    ->whereColumn('partners.id', 'borrowers.partner_id')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
        } elseif ($sortField === 'full_name') {
            // full_name is an accessor (first/middle/last), not a column, so
            // ordering by it directly is an SQL error. Order by its parts in the
            // order the name is displayed.
            $query->orderBy('borrowers.first_name', $sortOrder)
                ->orderBy('borrowers.middle_name', $sortOrder)
                ->orderBy('borrowers.last_name', $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'current_tier_name') {
            $query->orderBy(
                Tier::select('name')
                    ->whereColumn('tiers.id', 'borrowers.current_tier')
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
                'skip_sorting' => $skipSorting,
                'concat_fields' => [
                    'full_name' => ['borrowers.first_name', 'borrowers.last_name'],
                ],
                'aliases' => [
                    'email' => 'borrowers.email',
                    'phone_number' => 'borrowers.phone_number',
                    'first_name' => 'borrowers.first_name',
                    'last_name' => 'borrowers.last_name',
                    'status' => 'borrowers.status',
                    'created_at' => 'date_range',
                ],
            ]
        );

        // Calculate statistics from the partner-scoped (but otherwise unfiltered) query, so the
        // summary cards show true totals rather than fluctuating with the table's search/filters.
        // The host's split of its book: its own clients against its partners'.
        $hostPartnerIds = Partner::where('is_host', true)->select('id');

        $statistics = [
            'total_borrowers' => (clone $statsQuery)->count(),
            'active_borrowers' => (clone $statsQuery)->where('status', Borrower::STATUS_ACTIVE)->count(),
            'inactive_borrowers' => (clone $statsQuery)->where('status', Borrower::STATUS_INACTIVE)->count(),
            'suspended_borrowers' => (clone $statsQuery)->where('status', Borrower::STATUS_SUSPENDED)->count(),
            'host_borrowers' => (clone $statsQuery)->whereIn('borrowers.partner_id', $hostPartnerIds)->count(),
            'partner_borrowers' => (clone $statsQuery)->whereNotIn('borrowers.partner_id', $hostPartnerIds)->count(),
            'new_this_month' => (clone $statsQuery)->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerResource::class,
            __('borrower.success.retrieved'),
            200,
            ['statistics' => $statistics]
        );
    }

    public function getBorrowerProfileStats(int $id)
    {
        Borrower::visibleTo(auth()->user())->findOrFail($id);
        $borrowerClass = str_replace('\\', '\\\\', Borrower::class);

        $stats = DB::table('borrowers as b')
            ->select([
                'b.id',
                DB::raw("(SELECT COUNT(*) FROM appointments WHERE entity_type = '{$borrowerClass}' AND entity_id = b.id) as total_meetings"),
                DB::raw("(SELECT COUNT(*) FROM invoices WHERE borrower_id = b.id OR (entity_type = '{$borrowerClass}' AND entity_id = b.id)) as total_invoices"),
                DB::raw("(SELECT COALESCE((SELECT enrollment_type FROM subscriptions WHERE entity_type = '{$borrowerClass}' AND entity_id = b.id ORDER BY id DESC LIMIT 1), 'N/A')) as enrollment_type"),
                DB::raw("(SELECT COUNT(*) FROM pre_qualification_requests WHERE borrower_id = b.id) as total_prequalification"),
                DB::raw("0 as total_tickets"),
                DB::raw("(SELECT COUNT(*) FROM borrower_documents WHERE borrower_id = b.id) as total_documents")
            ])
            ->where('b.id', $id)
            ->first();

        return $stats;
    }

    /**
     * Store a new borrower.
     */
    public function store(array $data)
    {
        DB::beginTransaction();
        try {

            $authUser = auth()->user();
            if (empty($data['partner_id'])) {
                $data['partner_id'] = $authUser->partner_id;
            } elseif ($authUser->partner && $authUser->partner->is_host != 1 && $data['partner_id'] != $authUser->partner_id) {
                // Non-hosts can only create borrowers for their own partner
                $data['partner_id'] = $authUser->partner_id;
            }

            $data['current_tier'] = Tier::orderBy('order', 'asc')->first()?->id;

            $isFreeTrial = isset($data['is_free_trail']) && ($data['is_free_trail'] == 1 || $data['is_free_trail'] === true || $data['is_free_trail'] === 'true');

            $data['status'] = $isFreeTrial ? Borrower::STATUS_ACTIVE : Borrower::STATUS_ONBOARDING;

            // Filter out fields that are not columns in the borrowers table or are used for mapping
            $exceptFields = [
                'plan_id',
                'enrollment',
                'enrollment_payment',
                'enrollment_amount',
                'enrollment_type',
                'payment_amount',
                'is_partner_paying',
                'amount',
                'business_other_city',
                'installment_applied',
                'installment_down_payment',
                'installment_months',
                'installment_monthly_amount',
                'installment_remaining_balance',
            ];
            $borrowerData = collect($data)->except($exceptFields)->toArray();
            $borrowerData['business_start_date'] = isset($data['business_start_date']) ? normalizeDate($data['business_start_date']) : null;
            $borrowerData['date_of_birth'] = isset($data['date_of_birth']) ? normalizeDate($data['date_of_birth']) : null;
            $borrowerData['free_trail_end_date'] = isset($data['free_trail_end_date']) ? normalizeDate($data['free_trail_end_date']) : null;

            // Handle city overrides if 'other' city is provided
            if (isset($data['other_city'])) {
                $borrowerData['city'] = $data['other_city'];
            }
            if (isset($data['business_other_city'])) {
                $borrowerData['business_city'] = $data['business_other_city'];
            }

            $borrower = Borrower::create($borrowerData);

            // Log Borrower Creation
            $this->activityLogService->logModelEvent($borrower, ActivityLog::LOG_TYPE_BORROWER_CREATE);

            // Create associated User if needed
            $userDataResult = null;
            $userDataResult = $this->createOrUpdateUser($data, $borrower);
            Log::info('User data', ['userDataResult' => $userDataResult]);

            $user = $userDataResult['user'];
            $plainPassword = $userDataResult['plain_password'];

            if ($user && $plainPassword) {
                $clientPartner = Partner::where('id', $data['partner_id'])->first();
                $authUser = Auth::user();
                $isHostCreating = $authUser && $authUser->partner && $authUser->partner->is_host;

                $mailData = [
                    'user' => $user,
                    'plain_password' => $plainPassword,
                    'business_info' => [
                        'company_name' => $clientPartner->company_name ?? 'Four Corner Funding',
                        'company_logo' => $clientPartner->company_logo ?? 'logo.png',
                        'email' => $clientPartner->email ?? config('mail.from.address'),
                        'phone_number' => $clientPartner->phone_number ?? '',
                        'address_line_1' => $clientPartner->address_line_1 ?? '',
                    ]
                ];

                $partner = Partner::find($borrower->partner_id);

                // ── FREE TRIAL PATH ───────────────────────────────────────────────────────
                if (!empty($data['is_free_trail']) && $data['is_free_trail']) {
                    $trialEndDate = $data['free_trail_end_date'] ?? null;

                    $this->activityLogService->logModelEvent(
                        $borrower,
                        ActivityLog::LOG_TYPE_BORROWER_FREE_TRIAL,
                        [],
                        null,
                        __('borrower.free_trial_created_log', ['date' => $trialEndDate ?? 'N/A'])
                    );

                    // With no end date there is nothing to be "active until"
                    // (BUG-143): the free plan welcome, not the trial bell.
                    $this->sendBorrowerMail(
                        $trialEndDate ? 'borrower.account.trial_started' : 'borrower.account.free_plan_started',
                        $user,
                        $user->email,
                        new \App\Mail\Trial\BorrowerTrialMail(array_merge($mailData, ['trial_end_date' => $trialEndDate]), $borrower->partner_id),
                        $borrower->partner_id,
                        $trialEndDate ? ['date' => $trialEndDate] : []
                    );

                    $isHostPartner = $partner && $partner->is_host == 1;
                    $partnerTypeChecking = $partner && in_array($partner->partner_type, [Partner::PARTNER_TYPE_WHITE_LABEL, Partner::PARTNER_TYPE_CERTIFIED]);
                    if (!$isHostPartner && $partnerTypeChecking && !$isHostCreating) {
                        $borrower->update(['partner_payment_for_me' => false]);
                    } else {
                        $borrower->update(['partner_payment_for_me' => true]);
                    }
                }
                // ── PAID / STANDARD PATH ─────────────────────────────────────────────────
                else {
                    $this->enrollmentService->enrollBorrower(
                        $borrower,
                        $partner,
                        $user,
                        $data['enrollment'] ?? [],
                        $isHostCreating,
                        false,
                        $plainPassword
                    );
                }
            }
            DB::commit();

            return [
                'borrower' => $borrower->fresh(),
                'user' => $userDataResult['user'] ?? null,
                'plain_password' => $userDataResult['plain_password'] ?? null,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Borrower creation failed', ['error' => $e->getMessage()]);
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
            $user = $borrower->user;

            // With a login, a new email is confirmed from the new address before
            // either record changes; without one it applies directly.
            $newEmail = $this->emailChange->takeFrom($data, $user, $borrower->email);

            $wasFreeTrial = (bool) $borrower->is_free_trail;
            $isFreeTrialInput = isset($data['is_free_trail']) ? (bool) $data['is_free_trail'] : false;

            if (isset($data['business_logo']) && $data['business_logo'] instanceof UploadedFile) {
                if ($borrower->business_logo) {
                    $this->deleteFile($borrower->business_logo, 'public');
                }
                $upload = $this->uploadFile($data['business_logo'], "borrowers/{$borrower->id}", 'public');
                $data['business_logo'] = $upload['path'];
            }
            $exceptFields = [
                'enrollment',
                'enrollment_payment',
                'enrollment_amount',
                'enrollment_type',
                'plan_id',
                'is_partner_paying',
                'amount',
                'payment_amount',
                'business_other_city',
                'installment_applied',
                'installment_down_payment',
                'installment_months',
                'installment_monthly_amount',
                'installment_remaining_balance',
            ];
            $borrowerData = collect($data)->except($exceptFields)->toArray();
            $borrowerData['business_start_date'] = isset($data['business_start_date']) ? normalizeDate($data['business_start_date']) : $borrower->business_start_date;
            $borrowerData['date_of_birth'] = isset($data['date_of_birth']) ? normalizeDate($data['date_of_birth']) : $borrower->date_of_birth;
            // free_trail_end_date is a DATE column and is now serialised verbatim
            // through toDateOnly(), so a reposted value round-trips unchanged.
            if (isset($borrowerData['free_trail_end_date'])) {
                $borrowerData['free_trail_end_date'] = normalizeDate($borrowerData['free_trail_end_date']);
            }

            // Handle city overrides if 'other' city is provided
            if (isset($data['other_city'])) {
                $borrowerData['city'] = $data['other_city'];
            }
            if (isset($data['business_other_city'])) {
                $borrowerData['business_city'] = $data['business_other_city'];
            }
            if ($wasFreeTrial && !$isFreeTrialInput) {
                $borrowerData['status'] = Borrower::STATUS_ONBOARDING;
            }
            $oldStatus = $borrower->status;
            $borrower->update($borrowerData);

            // Sync user status if borrower status changed
            if ($borrower->status !== $oldStatus) {
                if (in_array($borrower->status, [Borrower::STATUS_SUSPENDED, Borrower::STATUS_INACTIVE])) {
                    $this->restrictBorrowerUser($borrower);
                } elseif ($borrower->status === Borrower::STATUS_ACTIVE) {
                    $this->reactivateBorrowerUser($borrower);
                }
            }

            $changes = $borrower->getChanges();
            unset($changes['updated_at']);

            if (isset($changes['email']) && strtolower(trim((string) $borrower->getOriginal('email'))) === strtolower(trim((string) $changes['email']))) {
                unset($changes['email']);
            }

            if (!empty($changes)) {
                $extraProps = [];
                if (isset($changes['email'])) {
                    $extraProps['new_email'] = $changes['email'];
                }

                $isEmailOnly = (count($changes) === 1 && isset($changes['email']));
                $contactFields = ['email', 'phone_number', 'business_phone'];

                $changedKeys = array_keys($changes);
                $isContactOnly = count(array_diff($changedKeys, $contactFields)) === 0;

                $logType = ActivityLog::LOG_TYPE_BORROWER_UPDATE;
                if ($isEmailOnly) {
                    $logType = ActivityLog::LOG_TYPE_EMAIL_UPDATE;
                } elseif ($isContactOnly) {
                    $logType = ActivityLog::LOG_TYPE_BORROWER_CONTACT_UPDATE;
                }

                $enrollmentType = $data['enrollment']['enrollment_type'] ?? null;
                $defaultEnrollmentType = __('borrower.default_enrollment_type');
                $transitionMsg = ($wasFreeTrial && !$isFreeTrialInput)
                    ? __('borrower.free_trial_converted_log', ['enrollment_type' => $enrollmentType ? $enrollmentType : $defaultEnrollmentType])
                    : null;

                $changedFields = [];
                foreach ($changes as $field => $newValue) {
                    if ($transitionMsg && in_array($field, ['is_free_trail', 'free_trail_end_date', 'status'])) {
                        continue;
                    }

                    // Who the client is assigned to, by name rather than user id
                    if ($field === 'assign_id') {
                        $owner = $this->activityLogService->describeOwnerChange(
                            __('activity_log.owner_label_client'),
                            $borrower->getPrevious()['assign_id'] ?? null,
                            $newValue
                        );
                        $changedFields[] = $owner['sentence'];
                        $extraProps['changes']['assign_id'] = $owner['change'];
                        continue;
                    }

                    // An SSN or date of birth is recorded as changed, never repeated
                    if (ActivityLog::isSensitiveField($field)) {
                        $changedFields[] = __('activity_log.sensitive_field_updated', ['field' => ActivityLog::fieldLabel($field)]);
                        continue;
                    }

                    $name = ucwords(str_replace('_', ' ', $field));
                    $val = (string) $newValue;
                    $val = $val === '' ? __('borrower.empty_value') : $val;
                    $changedFields[] = __('borrower.field_updated', ['field' => $name, 'value' => $val]);
                }

                if ($transitionMsg) {
                    $customDescription = !empty($changedFields)
                        ? "{$transitionMsg}. " . implode(', ', $changedFields) . "."
                        : "{$transitionMsg}.";
                } else {
                    $fieldsList = implode(', ', $changedFields);
                    $customDescription = $isEmailOnly ? null : "{$fieldsList}.";
                }

                // Only the Assigned To changed: an entry saying just that
                $customSubject = array_keys($changes) === ['assign_id'] ? __('activity_log.owner_subject_client') : null;

                $this->activityLogService->logModelEvent(
                    $borrower,
                    $logType,
                    $extraProps,
                    $customSubject,
                    $customDescription
                );
            }

            // 1. Update associated User if exists or create if email provided
            $user = $borrower->user;
            if ($user) {
                $userResult = $this->createOrUpdateUser($data, $borrower, $user);
                $user = $userResult['user'];
                $plainPassword = $userResult['plain_password'];

                if ($plainPassword) {
                    $hostPartner = Partner::where('is_host', 1)->first();
                    $mailData = [
                        'user' => $user,
                        'plain_password' => $plainPassword,
                        'business_info' => [
                            'company_name' => $hostPartner->company_name ?? 'Four Corner Funding',
                            'company_logo' => $hostPartner->company_logo ?? 'logo.png',
                            'email' => $hostPartner->email ?? config('mail.from.address'),
                            'phone_number' => $hostPartner->phone_number ?? '',
                            'address_line_1' => $hostPartner->address_line_1 ?? '',
                        ],
                        'is_update' => true,
                    ];
                    Log::info('📧 Sending account update email to borrower due to email change', ['email' => $user->email]);
                    $this->sendBorrowerMail('borrower.account.login_updated', $user, $user->email, new BorrowerAccountCreatedMail($mailData, $borrower->partner_id), $borrower->partner_id);
                }
            } elseif (!empty($data['email'])) {
                $userResult = $this->createOrUpdateUser($data, $borrower);
                $user = $userResult['user'];
            }

            // Update subscription if custom enrollment payment is provided
            $subscription = $borrower->latestSubscription;
            $isFreeTrialInput = isset($data['is_free_trail']) ? (bool) $data['is_free_trail'] : false;

            if ($wasFreeTrial && !$isFreeTrialInput && $user) {
                // Transition off free trial
                $authUser = Auth::user();
                $isHostCreating = $authUser && $authUser->partner && $authUser->partner->is_host;
                $partner = Partner::find($borrower->partner_id);

                $this->enrollmentService->enrollBorrower(
                    $borrower,
                    $partner,
                    $user,
                    $data['enrollment'] ?? [],
                    $isHostCreating,
                    isMigration: true
                );
            }

            if ($user) {
                $user->update(['is_refresh' => true]);
            }
            DB::commit();

            if ($newEmail) {
                $this->emailChange->request($user, $newEmail);
            }

            // Return consistent format or just borrower? Controller expects resource.
            // Keeping it simple as controller will be updated to handle array for store, but update often doesn't need email.
            return $borrower->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Borrower update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update borrower business financials.
     */
    public function updateBusinessFinancials(Borrower $borrower, array $data)
    {
        $bankAccountsData = null;
        if (array_key_exists('bank_accounts', $data)) {
            $bankAccountsData = collect($data['bank_accounts'] ?? []);
            unset($data['bank_accounts']);
        }

        $borrower->update($data);
        $changes = $borrower->getChanges();
        if (isset($changes['updated_at'])) {
            unset($changes['updated_at']);
        }

        $bankAccountsChanged = false;
        
        // Sync bank accounts
        if ($bankAccountsData !== null) {
            $incomingIds = $bankAccountsData->pluck('id')->filter()->values()->toArray();

            $deleted = BorrowerBankAccount::where('borrower_id', $borrower->id)
                ->whereNotIn('id', $incomingIds)
                ->delete();
                
            if ($deleted > 0) {
                $bankAccountsChanged = true;
            }

            foreach ($bankAccountsData as $row) {
                $accountOpenDate = !empty($row['account_open_date']) ? normalizeDate($row['account_open_date']) : null;
                if (!empty($row['id'])) {
                    $bba = BorrowerBankAccount::where('id', $row['id'])
                        ->where('borrower_id', $borrower->id)
                        ->first();
                    if ($bba) {
                        $bba->fill([
                            'bank_name' => $row['bank_name'],
                            'account_open_date' => $accountOpenDate,
                        ]);
                        if ($bba->isDirty()) {
                            $bba->save();
                            $bankAccountsChanged = true;
                        }
                    }
                } else {
                    BorrowerBankAccount::create([
                        'borrower_id' => $borrower->id,
                        'bank_name' => $row['bank_name'],
                        'account_open_date' => $accountOpenDate,
                    ]);
                    $bankAccountsChanged = true;
                }
            }
        }
        
        $changedFields = [];
        foreach ($changes as $field => $newValue) {
            $name = ucwords(str_replace('_', ' ', $field));
            $val = (string) $newValue;
            $val = $val === '' ? 'empty' : $val;
            $changedFields[] = "{$name} was updated to {$val}";
        }

        if ($bankAccountsChanged) {
            $changedFields[] = "Bank Accounts were updated";
        }

        if (!empty($changedFields)) {
            $customDescription = implode(', ', $changedFields) . '.';
            $this->activityLogService->logModelEvent(
                $borrower,
                ActivityLog::LOG_TYPE_BORROWER_UPDATE,
                [],
                null,
                $customDescription
            );
        }

        return $borrower->fresh(['bankAccounts']);
    }

    /**
     * A client's number that the details only carry masked, in full (BUG-014).
     * Every reveal is logged on the client - who looked, never the number.
     *
     * @param  string  $field  one of REVEALABLE_FIELDS
     */
    public function revealField(Borrower $borrower, string $field): ?string
    {
        $label = ActivityLog::fieldLabel($field);

        $this->activityLogService->logSystemEvent(
            Borrower::class,
            $borrower->id,
            $borrower->partner_id,
            $borrower->id,
            "{$label} Viewed",
            "The full {$label} was viewed.",
        );

        // The SSN printed on the credit report, kept with the credit profile
        // (BUG-145) - not always the borrower's own.
        if ($field === 'credit_profile_ssn') {
            return $borrower->creditProfile?->personal_info['ssn'] ?? null;
        }

        return $borrower->{$field} ?: null;
    }

    /**
     * Get borrower details.
     */
    public function getDetails(int $id)
    {
        return Borrower::visibleTo(auth()->user())
            ->with('partner', 'latestSubscription', 'bankAccounts')
            ->find($id);
    }

    /**
     * Delete borrowers.
     */

    /**
     * Archive records so they leave the working list without being deleted.
     *
     * Presentation only: `status` is untouched, so archiving changes nothing about
     * login, billing or permissions. Already-archived rows are skipped rather than
     * re-stamped, so the original "who filed this away" survives a repeat action.
     */
    public function archive(array $ids)
    {
        try {
            DB::beginTransaction();

            $archived = 0;

            foreach (Borrower::visibleTo(auth()->user())->whereIn('borrowers.id', $ids)->get() as $record) {
                if ($record->archive()) {
                    $archived++;
                }
            }

            DB::commit();

            return ApiResponse::success(
                ['archived' => $archived],
                __('borrower.archived')
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Borrower archive failed', ['ids' => $ids, 'error' => $e->getMessage()]);

            return ApiResponse::error(__('borrower.archive_failed'), 400);
        }
    }

    /**
     * Return archived records to the working list. Nothing else about them changes -
     * archiving never altered the record, so restoring has nothing to rebuild.
     */
    public function unarchive(array $ids)
    {
        try {
            DB::beginTransaction();

            $restored = 0;

            foreach (Borrower::visibleTo(auth()->user())->whereIn('borrowers.id', $ids)->get() as $record) {
                if ($record->unarchive()) {
                    $restored++;
                }
            }

            DB::commit();

            return ApiResponse::success(
                ['restored' => $restored],
                __('borrower.unarchived')
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Borrower restore failed', ['ids' => $ids, 'error' => $e->getMessage()]);

            return ApiResponse::error(__('borrower.unarchive_failed'), 400);
        }
    }

    public function delete(array $ids)
    {
        // `user` is eager-loaded: deleting reads it, and with more than one
        // borrower a lazy load is refused outside production (the bulk delete
        // used to fail with "Failed to delete borrower(s)." on stage).
        $borrowers = Borrower::visibleTo(auth()->user())->with('user')->whereIn('borrowers.id', $ids)->get();

        // A client with a paid invoice is kept (the payment record must stay),
        // and so is one - paid or free - with a pre-qualification or funding
        // application. Refuse the whole selection and name each blocked client,
        // so the user knows to change their status instead.
        $blocked = $borrowers->reject(fn (Borrower $borrower) => $borrower->isDeletable());
        if ($blocked->isNotEmpty()) {
            // One client: why that client is kept. Several: name the blocked ones.
            $message = $borrowers->count() === 1
                ? $blocked->first()->notDeletableReason()
                : __('borrower.delete_blocked_paid_invoice_named', ['names' => $blocked->pluck('full_name')->implode(', ')]);

            return ApiResponse::error($message, 422, [
                'blocked' => $blocked->map(fn (Borrower $b) => [
                    'id' => $b->id,
                    'name' => $b->full_name,
                    'reason_code' => $b->notDeletableReasonCode(),
                ])->values(),
            ]);
        }

        try {
            DB::beginTransaction();
            foreach ($borrowers as $borrower) {
                $borrower->safeDeleteWithRelations();
            }
            DB::commit();

            return ApiResponse::success([], __('borrower.success.deleted'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Borrower deletion failed', ['ids' => $ids, 'error' => $e->getMessage()]);
            return ApiResponse::error(__('borrower.delete_failed'), 400);
        }
    }

    /**
     * Check if multiple borrowers are deletable
     */
    public function checkDeletable(array $ids): array
    {
        $borrowers = Borrower::visibleTo(auth()->user())->whereIn('borrowers.id', $ids)->get();
        $results = [];

        foreach ($borrowers as $borrower) {
            $reasonCode = $borrower->notDeletableReasonCode();
            $results[] = [
                'id' => $borrower->id,
                'name' => $borrower->full_name,
                'is_deletable' => $reasonCode === null,
                'reason' => $reasonCode !== null ? $borrower->notDeletableReason() : null,
                'reason_code' => $reasonCode,
            ];
        }

        return $results;
    }

    /**
     * Create or update user for borrower.
     */
    private function createOrUpdateUser(array $data, Borrower $borrower, ?User $existingUser = null)
    {
        $userData = [];

        if (isset($data['first_name']) && isset($data['last_name'])) {
            $userData['name'] = $data['first_name'] . ' ' . $data['last_name'];
        }

        if (isset($data['email'])) {
            $userData['email'] = $data['email'];
        }

        if (isset($data['phone_number'])) {
            $userData['phone_number'] = $data['phone_number'];
        }

        if (!$existingUser) {
            // Defaults for new user
            $userData['user_type'] = User::BORROWER;
            $userData['entity_type'] = Borrower::class;
            $userData['entity_id'] = $borrower->id;
            $userData['partner_id'] = $borrower->partner_id;
        }

        if ($existingUser) {
            // A login's email never changes here — update() hands a new one to
            // EmailChangeService — so there's no password to reset.
            $plainPassword = null;
            // Check if email is changing and present in update data
            if (isset($userData['email']) && $existingUser->email !== $userData['email']) {
                $conflictUser = User::where('email', $userData['email'])->where('id', '!=', $existingUser->id)->first();
                if ($conflictUser) {
                    $entityName = $conflictUser->entity_name ?? class_basename($conflictUser->entity_type ?? 'User');
                    throw ValidationException::withMessages([
                        'email' => ["The email '{$userData['email']}' is already registered to an existing {$entityName} account."],
                    ]);
                }

                $plainPassword = bin2hex(random_bytes(4)); // Generate 8 char hex string (4 bytes)
                $userData['password'] = bcrypt($plainPassword);
                Log::info('Password reset for Borrower user due to email change', ['user_id' => $existingUser->id]);
            }

            $existingUser->update($userData);
            return ['user' => $existingUser, 'plain_password' => $plainPassword];
        }

        // Check if user exists by email to avoid duplicates if not linked
        $user = User::where('email', $data['email'])->first();
        if ($user) {
            // Guard against hijacking existing users from other entities or other borrowers
            $isSameBorrower = ($user->entity_type === Borrower::class && (int) $user->entity_id === (int) $borrower->id);
            if (!$isSameBorrower) {
                $entityName = $user->entity_name ?? class_basename($user->entity_type ?? 'User');
                throw ValidationException::withMessages([
                    'email' => ["The email '{$data['email']}' is already registered to an existing {$entityName} account."],
                ]);
            }

            $user->update($userData);
            return ['user' => $user, 'plain_password' => null];
        }

        $plainPassword = bin2hex(random_bytes(4)); // Generate 8 char hex string (4 bytes)
        $userData['password'] = bcrypt($plainPassword);

        $newUser = User::create($userData);

        // Assign 'Borrower' role based on partner_id
        $role = Role::where('name', User::BORROWER)
            ->where('partner_id', $borrower->partner_id)
            ->first();

        if ($role) {
            $newUser->assignRole($role);
        }

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

        // A list of other people's contact details is for the partner's team,
        // never for a borrower.
        if (!$authUser || $authUser->entity_type === Borrower::class) {
            return collect();
        }

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


    /**
     * Get Borrower Documents.
     */
    public function getBorrowerDocuments(int $borrowerId)
    {
        Borrower::visibleTo(auth()->user())->findOrFail($borrowerId);

        $documents = BorrowerDocument::with('documentType')
            ->where('borrower_id', $borrowerId)
            ->withoutCreditReports()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doc) {
                $size = '0 KB';
                $relativePath = $doc->file_path;
                if ($relativePath && str_contains($relativePath, '/storage/')) {
                    $relativePath = substr($relativePath, strpos($relativePath, '/storage/') + strlen('/storage/'));
                }

                // Use relative path for storage operations
                if ($relativePath && Storage::disk('public')->exists($relativePath)) {
                    $sizeBytes = Storage::disk('public')->size($relativePath);
                    $size = $this->formatSizeUnits($sizeBytes);
                }

                return [
                    'id' => $doc->id,
                    'document_number' => $doc->document_number,
                    'document_name' => $doc->file_name ?: ($doc->getRawOriginal('file_path') ? basename($doc->getRawOriginal('file_path')) : 'N/A'),
                    'type' => $doc->documentType->name ?? 'Other',
                    'rejection_reason' => $doc->rejection_reason ?? '',
                    'size' => $doc->file_size ?: '0 KB',
                    'status' => $doc->status ?: BorrowerDocument::STATUS_UPLOADED,
                    'date' => $doc->created_at ? $doc->created_at->format('Y-m-d') : 'N/A',
                    'document_url' => $doc->file_path,
                    'url' => $doc->file_path,
                    'download_url' => url("api/v1/common/borrowers-documents/download/{$doc->id}"),
                ];
            });


        return $documents;
    }

    private function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }
    /**
     * Force logout for suspended/inactive borrowers.
     */
    private function restrictBorrowerUser(Borrower $borrower): void
    {
        $user = $borrower->user;
        if ($user) {
            // Force Logout (Revoke all tokens)
            DB::table('oauth_access_tokens')
                ->where('user_id', $user->id)
                ->update(['revoked' => true]);

            Log::info("Force logged out borrower user for Borrower ID: {$borrower->id} (User ID: {$user->id}) due to borrower status being set to {$borrower->status}");
        }
    }

    /**
     * Log reactivation of borrower.
     */
    private function reactivateBorrowerUser(Borrower $borrower): void
    {
        Log::info("Borrower user for Borrower ID: {$borrower->id} is eligible for login again.");
    }
}
