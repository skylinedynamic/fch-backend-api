<?php

namespace App\Services\FCH\Common\CreditBuilder;

use App\Traits\PaginatesApi;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\Tier;
use Illuminate\Support\Facades\DB;
use App\Services\Global\Datatable\QueryFilter;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Http\Resources\FCH\CreditBuilder\BorrowerTaskVerificationRequestResource;
use App\Models\Borrower\MigratedTask;
use App\Models\CreditBuilder\TierRelatedApi;
use App\Models\CreditBuilder\TierTask;
use App\Services\Global\MicroBilt\MicroBiltService;
use App\Services\Global\MicroBilt\MicroBiltVerificationFactory;
use App\Mail\Borrower\BorrowerVerificationStatusMail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\FCH\Common\Setting\EmailConfigurationService;

class VerificationCenterService
{
    use PaginatesApi;

    public function __construct(
        protected EmailConfigurationService $emailConfigService
    ) {
    }

    /**
     * Fetch all borrower task verification requests with related data.
     */
    public function fetchAll(array $filters)
    {
        $query = BorrowerTaskVerificationRequest::query();

        $authUser = auth()->user();


        // Apply partner security filter
        if ($authUser && $authUser->partner && $authUser->partner->is_host == 1) {
            // If user is a host, show all data by default, or filter by partner_id if provided
            if (!empty($filters['partner_id'])) {
                $query->where('partner_id', $filters['partner_id']);
            }
        } elseif ($authUser && $authUser->partner_id) {
            // If user is a standard partner, only show data from their own partner
            $query->where('partner_id', $authUser->partner_id);
        } elseif (!empty($filters['partner_id'])) {
            // If user has no partner (Admin), they can filter by partner_id if provided
            $query->where('partner_id', $filters['partner_id']);
        }

        $query->select(
            'borrower_id',
            'partner_id',
            'tier_id',
            DB::raw("COUNT(CASE WHEN status = '" . BorrowerTaskVerificationRequest::PROCESSING . "' THEN 1 END) as pending_count"),
            DB::raw('MAX(created_at) as request_date'),
            DB::raw("DATE_FORMAT(MAX(created_at), '%M %d, %Y %h:%i %p') as formatted_request_date"),
            DB::raw('MIN(id) as id')
        )
            ->addSelect([
                'borrower_name' => Borrower::select(DB::raw("CONCAT(first_name, ' ', last_name)"))
                    ->whereColumn('borrowers.id', 'borrower_task_verification_requests.borrower_id')
                    ->limit(1),
                'partner_name' => Partner::select('full_name')
                    ->whereColumn('partners.id', 'borrower_task_verification_requests.partner_id')
                    ->limit(1),
                'tier_name' => Tier::select('name')
                    ->whereColumn('tiers.id', 'borrower_task_verification_requests.tier_id')
                    ->limit(1),
            ])
            ->with(['borrower:id,first_name,last_name', 'partner:id,full_name', 'tier:id,name'])
            ->groupBy('borrower_id', 'partner_id', 'tier_id')
            ->having('pending_count', '>', 0);

        // Search and Filters using HAVING for grouped columns and subqueries
        $inputFilters = $filters['filters'] ?? [];

        foreach ($inputFilters as $field => $filterData) {
            $constraints = is_array($filterData) && isset($filterData['constraints'])
                ? $filterData['constraints']
                : [['value' => is_array($filterData) ? ($filterData['value'] ?? null) : $filterData]];

            foreach ($constraints as $constraint) {
                $val = $constraint['value'] ?? null;
                if ($val === null || $val === '')
                    continue;

                if ($field === 'borrower_name') {
                    $query->having('borrower_name', 'LIKE', "%{$val}%");
                } elseif ($field === 'tier_name') {
                    $query->having('tier_name', 'LIKE', "%{$val}%");
                } elseif ($field === 'partner_name') {
                    $query->having('partner_name', 'LIKE', "%{$val}%");
                } elseif ($field === 'pending_count') {
                    $query->having('pending_count', '=', (int) $val);
                } elseif ($field === 'request_date') {
                    $query->having('formatted_request_date', 'LIKE', "%{$val}%");
                }
            }
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->having(function ($q) use ($search) {
                $q->having('borrower_name', 'LIKE', "%{$search}%")
                    ->orHaving('partner_name', 'LIKE', "%{$search}%")
                    ->orHaving('tier_name', 'LIKE', "%{$search}%")
                    ->orHaving('formatted_request_date', 'LIKE', "%{$search}%");

                if (is_numeric($search)) {
                    $q->orHaving('pending_count', '=', (int) $search);
                }
            });
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'request_date';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'borrower_name') {
            $query->orderBy(
                Borrower::select(DB::raw("CONCAT(first_name, ' ', last_name)"))
                    ->whereColumn('borrowers.id', 'borrower_task_verification_requests.borrower_id')
                    ->limit(1),
                $sortOrder
            );
        } elseif ($sortField === 'partner_name') {
            $query->orderBy(
                Partner::select('full_name')
                    ->whereColumn('partners.id', 'borrower_task_verification_requests.partner_id')
                    ->limit(1),
                $sortOrder
            );
        } elseif ($sortField === 'tier_name') {
            $query->orderBy(
                Tier::select('name')
                    ->whereColumn('tiers.id', 'borrower_task_verification_requests.tier_id')
                    ->limit(1),
                $sortOrder
            );
        } elseif ($sortField === 'pending_count') {
            $query->orderBy('pending_count', $sortOrder);
        } else {
            $query->orderBy('request_date', $sortOrder);
        }

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerTaskVerificationRequestResource::class,
            __('verification_center.success_retrieved')
        );
    }

    /**
     * Fetch all verification requests for a specific borrower.
     */
    public function fetchBorrowerRequests(array $filters)
    {
        $query = BorrowerTaskVerificationRequest::query()
            ->with(['borrower:id,first_name,last_name,status', 'tier:id,name,code', 'task:id,title,related_api_id', 'partner:id,full_name']);

        if (!empty($filters['borrower_id'])) {
            $query->where('borrower_id', $filters['borrower_id']);
        }


        if (!empty($filters['tier_id'])) {
            $query->where('tier_id', $filters['tier_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply partner security filter if not already filt`ered or restricted
        $authUser = auth()->user();
        if ($authUser && $authUser->partner && $authUser->partner->is_host != 1) {
            $query->where('partner_id', $authUser->partner_id);
        }

        // Global search across task title and tier name
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereHas('task', function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%");
                })->orWhereHas('tier', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%");
                })->orWhere('status', 'LIKE', "%{$search}%")
                    ->orWhereRaw("DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') LIKE ?", ["%{$search}%"]);
            });
        }

        // PrimeReact DataTable filters (Nested constraints)
        if (!empty($filters['filters'])) {
            foreach ($filters['filters'] as $field => $filterData) {
                if (is_array($filterData) && isset($filterData['constraints'])) {
                    foreach ($filterData['constraints'] as $constraint) {
                        $value = $constraint['value'] ?? null;
                        if ($value === null || $value === '')
                            continue;

                        $query->where(function ($q) use ($field, $value) {
                            if ($field === 'task_title') {
                                $q->whereHas('task', function ($sq) use ($value) {
                                    $sq->where('title', 'LIKE', "%{$value}%");
                                });
                            } elseif ($field === 'tier_name') {
                                $q->whereHas('tier', function ($sq) use ($value) {
                                    $sq->where('name', 'LIKE', "%{$value}%");
                                });
                            } elseif ($field === 'borrower_name') {
                                $q->whereHas('borrower', function ($sq) use ($value) {
                                    $sq->where(DB::raw("CONCAT(first_name, ' ', last_name)"), 'LIKE', "%{$value}%");
                                });
                            } elseif ($field === 'partner_name') {
                                $q->whereHas('partner', function ($sq) use ($value) {
                                    $sq->where('full_name', 'LIKE', "%{$value}%");
                                });
                            } elseif ($field === 'status') {
                                $q->where('status', 'LIKE', "%{$value}%");
                            } elseif ($field === 'request_date') {
                                $q->whereRaw("DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') LIKE ?", ["%{$value}%"]);
                            }
                        });
                    }
                }
            }
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'created_at';
        if ($sortField === 'request_date') {
            $sortField = 'created_at';
        }
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'task_name') {
            $query->join('tier_tasks', 'borrower_task_verification_requests.task_id', '=', 'tier_tasks.id')
                ->orderBy('tier_tasks.title', $sortOrder)
                ->select('borrower_task_verification_requests.*');
        } elseif ($sortField === 'tier_name') {
            $query->join('tiers', 'borrower_task_verification_requests.tier_id', '=', 'tiers.id')
                ->orderBy('tiers.name', $sortOrder)
                ->select('borrower_task_verification_requests.*');
        } else {
            $query->orderBy('borrower_task_verification_requests.' . $sortField, $sortOrder);
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            \App\Http\Resources\FCH\CreditBuilder\BorrowerTaskVerificationRequestDetailResource::class,
            __('verification_center.success_retrieved')
        );
    }


    public function updateMicrobiltStatus($borrower, $task, $tier, $api)
    {
        try {
            $strategy = MicroBiltVerificationFactory::make($api->name);

            if ($strategy) {
                $microBiltService = app(MicroBiltService::class);
                $response = $strategy->execute($microBiltService, $borrower);
                Log::info("MicroBilt {$api->name} Response", ['response' => $response]);

                // Check StatusCode and StatusDesc in the response
                // Structure expected: {"response": {"MsgRsHdr": {"Status": {"StatusCode": 0, "StatusDesc": "OK"}}}}
                // We handle both direct and 'response'-wrapped responses.
                $msgRsHdr = $response['MsgRsHdr'] ?? $response['response']['MsgRsHdr'] ?? null;
                $status = $msgRsHdr['Status'] ?? null;

                $isSuccess = false;
                if ($status && isset($status['StatusCode']) && $status['StatusCode'] === 0 && ($status['StatusDesc'] ?? '') === 'OK') {
                    $isSuccess = true;
                }

                if (!$isSuccess) {
                    $errorDesc = $status['StatusDesc'] ?? 'Unknown status';
                    Log::warning("MicroBilt {$api->name} Status Not OK", ['status' => $status]);
                }

                return [
                    'success' => $isSuccess,
                    'response' => $response
                ];
            }
        } catch (\Exception $e) {
            Log::error("MicroBilt {$api->name} Failed", ['error' => $e->getMessage()]);
            throw $e;
        }

        return [
            'success' => false,
            'response' => null
        ];
    }

    /**
     * Update verification request status.
     */
    public function updateStatus(array $data)
    {
        $requestIds = (array) $data['request_ids'];
        $isApi = filter_var($data['is_api'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $statusToSet = $data['status'];

        $verificationRequests = BorrowerTaskVerificationRequest::with('task.api', 'tier', 'task.vendor', 'borrower')
            ->whereIn('id', $requestIds)
            ->get();

        if ($verificationRequests->isEmpty()) {
            return [];
        }

        $borrower = $verificationRequests->first()->borrower;
        $results = [];

        foreach ($verificationRequests as $verificationRequest) {
            $task = $verificationRequest->task;
            $tier = $verificationRequest->tier;
            $taskName = $task->title ?? 'Unknown Task';

            // Do not verify or reject any task that is already Completed or Rejected
            if (in_array($verificationRequest->status, [BorrowerTaskVerificationRequest::COMPLETED, BorrowerTaskVerificationRequest::REJECTED])) {
                $results[] = [
                    'task_name' => $taskName,
                    'is_verified' => ($verificationRequest->status == BorrowerTaskVerificationRequest::COMPLETED),
                    'message' => ($verificationRequest->status == BorrowerTaskVerificationRequest::COMPLETED)
                        ? __('verification_center.already_verified')
                        : __('verification_center.already_rejected'),
                    'skipped' => true,
                ];
                continue;
            }

            $canUpdateStatus = false;
            $shouldSkipUpdate = false;
            $errorMessage = '';
            $verifiedWith = $isApi ? BorrowerTaskVerificationRequest::MICROBILT_VERIFIED : BorrowerTaskVerificationRequest::MANUALLY_VERIFIED;

            if ($isApi && $statusToSet == BorrowerTaskVerificationRequest::COMPLETED) {
                // Only T0 will be api verification
                if ($tier->code == 'T0') {
                    $api = $task->api;

                    if ($api) {
                        try {
                            if ($api->provider == TierRelatedApi::PROVIDER_MICROBILT) {
                                $microbiltResult = $this->updateMicrobiltStatus($borrower, $task, $tier, $api);
                                $verificationRequest->microbilt_response = $microbiltResult['response'];

                                if ($microbiltResult['success']) {
                                    $canUpdateStatus = true;
                                } else {
                                    $errorMessage = __('verification_center.no_matching_business_record');
                                    $canUpdateStatus = false;
                                }
                            } else {
                                $errorMessage = __('verification_center.no_matching_business_record');
                                $canUpdateStatus = false;
                            }
                        } catch (\Exception $e) {
                            $errorMessage = __('verification_center.no_matching_business_record');
                            $canUpdateStatus = false;
                        }
                    } else {
                        // If null do not verify keep their status unchanged
                        $errorMessage = __('verification_center.no_api_assigned');
                        $shouldSkipUpdate = true;
                    }
                } else {
                    $api = $task->api;

                    if ($api) {
                        $errorMessage = __('verification_center.no_matching_business_record');
                        $canUpdateStatus = false;
                    } else {
                        $errorMessage = __('verification_center.no_api_assigned');
                        $shouldSkipUpdate = true;
                    }

                }
            } else {
                // Manual verification or non-completed status update
                $canUpdateStatus = true;
            }

            if (!$shouldSkipUpdate) {
                // Always update status and note in database for every request
                $statusToWrite = $canUpdateStatus ? $statusToSet : BorrowerTaskVerificationRequest::REJECTED;

                // Only trigger tier update if it's successfully transitioning to COMPLETED
                $shouldTriggerTierUpdate = ($statusToWrite == BorrowerTaskVerificationRequest::COMPLETED && $verificationRequest->status != BorrowerTaskVerificationRequest::COMPLETED);

                $noteToSave = ($statusToWrite == BorrowerTaskVerificationRequest::COMPLETED)
                    ? ($data['note'] ?? null)
                    : __('verification_center.no_matching_business_record');

                $verificationRequest->update([
                    'status' => $statusToWrite,
                    'verified_by' => Auth::id(),
                    'verified_at' => now(),
                    'note' => $noteToSave,
                    'verified_with' => $verifiedWith,
                    'microbilt_response' => $verificationRequest->microbilt_response,
                ]);

                if ($shouldTriggerTierUpdate) {
                    $this->tierUpdate($verificationRequest, BorrowerTaskVerificationRequest::COMPLETED);
                }

                $isVerified = ($statusToWrite == BorrowerTaskVerificationRequest::COMPLETED);
                $results[] = [
                    'task_name' => $taskName,
                    'is_verified' => $isVerified,
                    'message' => $isVerified ? __('verification_center.verified') : ($errorMessage ?: __('verification_center.verification_failed')),
                ];
            } else {
                $results[] = [
                    'task_name' => $taskName,
                    'is_verified' => false,
                    'message' => $errorMessage ?: __('verification_center.no_api_assigned'),
                    'skipped' => true,
                ];
            }
        }

        // Send a consolidated verification status email to the borrower
        $this->sendVerificationStatusMail($borrower, $statusToSet, $data['note'] ?? null, $results);

        return $results;
    }

    /**
     * Send verification status notification email to the borrower.
     */
    protected function sendVerificationStatusMail($borrower, string $status, ?string $note, array $taskResults): void
    {
        try {
            // Only send if borrower has an email
            $email = $borrower?->email ?? $borrower?->user?->email ?? null;

            if (!$email) {
                Log::warning('VerificationCenterService: No email found for borrower', [
                    'borrower_id' => $borrower?->id,
                ]);
                return;
            }

            $borrowerName = trim(($borrower->first_name ?? '') . ' ' . ($borrower->last_name ?? '')) ?: 'Borrower';

            $statusLabel = match ($status) {
                BorrowerTaskVerificationRequest::COMPLETED => __('verification_center.verified'),
                BorrowerTaskVerificationRequest::REJECTED => __('verification_center.rejected'),
                BorrowerTaskVerificationRequest::PROCESSING => __('verification_center.processing'),
                BorrowerTaskVerificationRequest::PENDING => __('verification_center.pending'),
                default => ucfirst($status),
            };

            // Only send mail for tasks that were not skipped
            $filteredResults = array_filter($taskResults, function ($result) {
                return !($result['skipped'] ?? false);
            });

            if (empty($filteredResults)) {
                return;
            }

            $restore = $this->emailConfigService->applyToMailer($borrower->partner_id);
            try {
                Mail::to($email)->send(new BorrowerVerificationStatusMail([
                    'subject' => __('verification_center.verification_status_update') . ' - ' . $statusLabel,
                    'borrower_name' => $borrowerName,
                    'status' => $status,
                    'note' => $note,
                    'task_results' => $filteredResults,
                ]));
            } finally {
                $restore();
            }
        } catch (\Exception $e) {
            Log::error('VerificationCenterService: Failed to send verification status mail', [
                'borrower_id' => $borrower?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check and update borrower tier if all tasks are completed.
     */
    protected function tierUpdate(BorrowerTaskVerificationRequest $verificationRequest, string $status)
    {
        $tierId = $verificationRequest->tier_id;
        $borrowerId = $verificationRequest->borrower_id;

        $taskTier = Tier::find($tierId);

        if ($taskTier->code == 'T4') {
            return true;
        }

        $borrower = Borrower::with('tier')->find($borrowerId);

        // Count total tasks in this tier
        $totalTasks = TierTask::where('tier_id', $tierId)->count();

        // Count verified tasks for this borrower in this tier
        $verifiedTasks = BorrowerTaskVerificationRequest::where('borrower_id', $borrowerId)
            ->where('tier_id', $tierId)
            ->where('status', BorrowerTaskVerificationRequest::COMPLETED)
            ->count();


        if ($taskTier->code != 'T0') {
            $task = $verificationRequest->task;

            MigratedTask::create([
                'borrower_id' => $verificationRequest->borrower_id,
                'partner_id' => $verificationRequest->partner_id,
                'task_id' => $task->id,
                'vendor_id' => $task->vendor_id,
                'tier_from' => $verificationRequest->tier_id,
                'tier_to' => $verificationRequest->tier_id + 1,
                'migrated_by' => Auth::id(),
                'status' => BorrowerTaskVerificationRequest::PENDING //still no use
            ]);
        }

        if ($taskTier && $borrower) {
            // Determine mandatory task count for progression
            $requiredTasks = !is_null($taskTier->task_to_complete) ? $taskTier->task_to_complete : $totalTasks;

            // If verified tasks equal or exceed required amount, move to next tier
            if ($verifiedTasks >= $requiredTasks) {
                if ($taskTier->code == 'T0') {
                    // Find the next tier based on order
                    $nextTier = Tier::where('order', '>', $borrower->tier->order)
                        ->orderBy('order', 'asc')
                        ->first();

                    if ($nextTier) {
                        $borrower->update([
                            'current_tier' => $nextTier->id
                        ]);
                    }
                } else {
                    if ($borrower->current_tier <= $taskTier->id) {
                        $nextTier = Tier::where('order', '>', $borrower->tier->order)
                            ->orderBy('order', 'asc')
                            ->first();

                        $borrower->update([
                            'current_tier' => $nextTier->id
                        ]);
                    }
                }

            }
        }
    }
}
