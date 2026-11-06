<?php

namespace App\Services\FCH\Common\FundingRequest;

use App\Helpers\ApiResponse;
use App\Mail\FundingRequest\BorrowerFundingRequestApproved;
use App\Mail\FundingRequest\PartnerFundingRequestApproved;
use App\Mail\FundingRequest\UnderwritingStatusUpdated;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Lender\Lender;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Traits\PaginatesApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\Common\Notification\Notifier;
use Throwable;

class FundingRequestService
{
    use PaginatesApi;

    /**
     * Get Funding Request List
     */
    public function getFundingRequestList(array $filters)
    {
        $authUser = Auth::user();

        $isHost = $authUser->user_type === User::ADMIN || $authUser->user_type === User::EMPLOYEE || (isset($authUser->partner) && (int) ($authUser->partner->is_host ?? 0) === 1);

        $query = FundingRequest::with(['borrower', 'fundingSequence', 'partner', 'fundingApplication'])
            ->when(!$isHost && isset($authUser->partner), function ($q) use ($authUser) {
                $q->where('partner_id', $authUser->partner_id);
            });

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['funding_sequence_id'])) {
            $query->where('funding_sequence_id', $filters['funding_sequence_id']);
        }

        if (!empty($filters['application_status'])) {
            if ($filters['application_status'] === FundingRequest::APPLICATION_STATUS_NOT_STARTED) {
                $query->where('status', FundingRequest::STATUS_APPROVED)
                      ->doesntHave('fundingApplication');
            } else {
                $query->whereHas('fundingApplication', function ($q) use ($filters) {
                    $q->where('status', $filters['application_status']);
                });
            }
        }

        if (!empty($filters['borrower_id'])) {
            $query->where('borrower_id', $filters['borrower_id']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSortFields = ['id', 'created_at', 'funding_type', 'use_of_funds', 'requested_amount', 'status', 'funding_sequence_id', 'borrower_name', 'partner_name', 'lender_names', 'application_status'];

        if (in_array($sortField, $allowedSortFields)) {
            if ($sortField === 'borrower_name') {
                // The name as the column reads it, "first last" - first name
                // then last name put "QA-TEST" + "R2..." before "QA-TEST ONB"
                // + "..." (BUG-150).
                $query->orderBy(
                    Borrower::selectRaw("CONCAT_WS(' ', first_name, last_name)")
                        ->whereColumn('borrowers.id', 'funding_requests.borrower_id')
                        ->limit(1),
                    $sortOrder
                );
            } elseif ($sortField === 'application_status') {
                // APPLICATION STATUS is the latest application's status - not a
                // column here, so it never sorted (BUG-150). Lifecycle order,
                // "no application yet" first, as the borrower's list (BUG-009).
                [$case, $bindings] = FundingApplication::statusKeyCase('latest_application.status');
                $query->orderByRaw(
                    "COALESCE((SELECT {$case} FROM funding_applications AS latest_application"
                    . ' WHERE latest_application.funding_request_id = funding_requests.id'
                    . ' AND latest_application.deleted_at IS NULL'
                    . " ORDER BY latest_application.id DESC LIMIT 1), 0) {$sortOrder}",
                    $bindings
                );
            } elseif ($sortField === 'lender_names') {
                // ASSIGNED LENDER: the name of the lender set on the request. It
                // was not a sortable field, so the click fell back to newest first.
                $query->orderBy(
                    Lender::select('name')
                        ->whereColumn('lenders.id', 'funding_requests.assign_lender')
                        ->limit(1),
                    $sortOrder
                );
            } elseif ($sortField === 'partner_name') {
                $query->orderBy(
                    Partner::select('full_name')
                        ->whereColumn('partners.id', 'funding_requests.partner_id')
                        ->limit(1),
                    $sortOrder
                );
            } elseif ($sortField === 'funding_sequence_id') {
                // The FUNDING SEQUENCE column shows the sequence's name, so it
                // sorts by name - by id the order looked random (BUG-008).
                $query->orderBy(
                    FundingSequencing::select('name')
                        ->whereColumn('funding_sequencings.id', 'funding_requests.funding_sequence_id')
                        ->limit(1),
                    $sortOrder
                );
            } else {
                $query->orderBy('funding_requests.' . $sortField, $sortOrder);
            }
        }

        // Rows with an equal value (many $300,000 requests) need one fixed order,
        // or a row can show on two pages and another on none. Newest first is
        // also the list's default when nothing (or an unknown field) is sorted.
        $query->orderBy('funding_requests.id', 'desc');

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        
        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        // Fetch unique funding sequences used within the current user's scope to populate the dropdown accurately
        $fundingTypes = FundingSequencing::whereIn('id', function($q) use ($authUser, $isHost) {
            $q->select('funding_sequence_id')
              ->from('funding_requests')
              ->when(!$isHost && isset($authUser->partner), function ($query) use ($authUser) {
                  $query->where('partner_id', $authUser->partner_id);
              });
        })->get(['id', 'name']);

        return $this->respondWithPagination(
            $paginator, 
            __('funding_request.funding_requests_retrieved'),
            200,
            null,
            ['funding_types' => $fundingTypes]
        );
    }

    /**
     * Get Funding Request by ID
     */
    public function getFundingRequestById(int $id)
    {
        $authUser = Auth::user();

        $isHost = $authUser->user_type === User::ADMIN || $authUser->user_type === User::EMPLOYEE || (isset($authUser->partner) && (int) ($authUser->partner->is_host ?? 0) === 1);

        // borrower.borrowerDocuments: documents_with_urls lists the uploaded files
        // from these; without them every document showed as "Not Uploaded".
        $review = FundingRequest::with(['borrower.borrowerDocuments.documentType', 'fundingSequence', 'partner', 'fundingApplication'])
            ->where('id', $id)
            ->when(!$isHost && isset($authUser->partner), function ($q) use ($authUser) {
                $q->where('partner_id', $authUser->partner_id);
            })->first();

        if (!$review) {
            return ApiResponse::error(__('funding_request.funding_request_not_found'));
        }

        // The detail page decides between "Process" and "Edit Application" from
        // the application's lifecycle key, not from a list of status names.
        $review->fundingApplication?->append(['status_key', 'is_editable']);

        // Lender Information offers Assign only while the lender may change.
        $review->setAttribute('can_assign_lender', $review->canAssignLender());

        // When each status was reached, so the tracker keeps the dates of the
        // steps in between once the request is decided (BUG-070).
        $review->setAttribute('status_dates', $this->statusDates($review));

        return ApiResponse::success($review);
    }

    /**
     * Status => when the request last reached it, from the status-change log.
     * Submission is created_at, which the tracker already shows.
     *
     * @return array<string, string>
     */
    private function statusDates(FundingRequest $review): array
    {
        $dates = [];

        ActivityLog::where('subject_type', FundingRequest::class)
            ->where('subject_id', $review->id)
            ->where('log_name', ActivityLog::LOG_TYPE_FUNDING_REQUEST_STATUS_UPDATE)
            ->oldest()->oldest('id')
            ->get(['properties', 'created_at'])
            ->each(function ($log) use (&$dates) {
                $status = $log->properties['status'] ?? null;
                if ($status) $dates[$status] = $log->created_at->toIso8601String();
            });

        return array_filter($dates);
    }

    /**
     * Update Funding Request Status
     */
    public function fundingRequestStatusUpdate(array $data)
    {
        $authUser = Auth::user();

        // The read, the stale check and the write happen under a row lock, so
        // two decisions on the same request are taken one after the other and
        // the second sees what the first did (R2 BUG-048).
        [$review, $oldStatus] = DB::transaction(function () use ($data, $authUser) {
            $review = FundingRequest::with(['borrower.user', 'partner', 'fundingSequence'])
                ->where('id', $data['id'])
                ->when(isset($authUser->partner) && $authUser->partner->is_host == 0, function ($q) use ($authUser) {
                    $q->where('partner_id', $authUser->partner_id);
                })
                ->lockForUpdate()
                ->first();

            if (!$review) {
                return [null, null];
            }

            $oldStatus = $review->status;

            // Decided on a page that still showed an older status - e.g. a tab
            // left on Pending after the request was approved elsewhere. Nothing
            // is written and nobody is notified.
            if (!empty($data['expected_status']) && $data['expected_status'] !== $oldStatus) {
                return [$review, $oldStatus];
            }

            $review->status = $data['status'];

            if ($data['status'] === FundingRequest::STATUS_APPROVED) {
                $review->approved_at = Carbon::now();
                $review->approved_by = $authUser?->id;
                $review->reject_reason = null;
            } elseif ($data['status'] === FundingRequest::STATUS_REJECTED) {
                $review->reject_reason = $data['reject_reason'] ?? null;
                $review->approved_at = Carbon::now();
                $review->approved_by = $authUser?->id;
            }

            $review->save();

            return [$review, $oldStatus];
        });

        if (!$review) {
            Log::warning("fundingRequestStatusUpdate: Funding request not found or unauthorized.", [
                'funding_request_id' => $data['id'] ?? null,
                'user_id'            => $authUser?->id,
                'partner_id'         => $authUser?->partner_id,
            ]);
            return ApiResponse::error(__('funding_request.funding_request_not_found'));
        }

        if (!empty($data['expected_status']) && $data['expected_status'] !== $oldStatus) {
            return ApiResponse::error(
                __('funding_request.status_changed_elsewhere', ['status' => $oldStatus]),
                409,
                ['status' => $oldStatus]
            );
        }

        $review->loadMissing(['borrower.user', 'partner', 'fundingSequence']);

        $borrower = $review->borrower;
        $partner = $review->partner ?? $borrower?->partner;
        $hostPartner = Partner::host() ?? Partner::where('is_host', true)->first();

        $borrowerEmail = $borrower?->user?->email 
            ?? $borrower?->email 
            ?? $borrower?->business_email;

        $partnerEmail = null;
        $isNonHostPartner = $partner && !$partner->is_host;
        if ($isNonHostPartner) {
            $partnerEmail = $partner->email 
                ?? $partner->contact_person_email 
                ?? $partner->partnerUser?->email;
        }

        $hostEmail = $hostPartner?->email ?? config('mail.from.address');

        if ($oldStatus !== $data['status']) {
            Log::info("FundingRequest #{$review->id} Status Updated: '{$oldStatus}' -> '{$data['status']}'. Processing email notifications.", [
                'funding_request_id' => $review->id,
                'old_status'         => $oldStatus,
                'new_status'         => $data['status'],
                'updated_by_user_id' => $authUser?->id,
                'borrower_id'        => $review->borrower_id,
                'borrower_email'     => $borrowerEmail,
                'partner_id'         => $partner?->id,
                'partner_name'       => $partner?->company_name,
                'partner_type'       => $partner?->partner_type,
                'partner_email'      => $partnerEmail,
                'is_non_host_partner'=> $isNonHostPartner,
                'host_partner_id'    => $hostPartner?->id,
                'host_email'         => $hostEmail,
            ]);

            // Bell + email per audience. The bell goes out on every change, even
            // when there is no address to email; only a decision (Approved,
            // Rejected, Cancelled) is emailed.
            $notifier = app(Notifier::class);
            $isApproved = $data['status'] === FundingRequest::STATUS_APPROVED;
            $notificationData = ['id' => $review->id, 'status' => $data['status']];

            $sendsEmail = in_array($data['status'], FundingRequest::EMAILED_STATUSES, true);

            if (!$sendsEmail) {
                Log::info(__('funding_request.logs.status_not_emailed', ['id' => $review->id, 'status' => $data['status']]));
            }

            // --- 1. BORROWER ---
            if ($sendsEmail && !$borrowerEmail) {
                Log::warning("FundingRequest #{$review->id}: Skipped borrower email - No valid email address found for borrower ID {$review->borrower_id}");
            }

            try {
                $notifier->send('borrower.funding_request.status_changed', $borrower, $notificationData, mail: $sendsEmail && $borrowerEmail
                    ? ($isApproved ? new BorrowerFundingRequestApproved($review) : new UnderwritingStatusUpdated($review))->to($borrowerEmail)
                    : null);

                if ($sendsEmail && $borrowerEmail) {
                    Log::info(__('funding_request.logs.dispatched_to_borrower', [
                        'id' => $review->id,
                        'mailable' => $isApproved ? 'BorrowerFundingRequestApproved' : "UnderwritingStatusUpdated ({$data['status']})",
                        'email' => $borrowerEmail,
                    ]));
                }
            } catch (Throwable $e) {
                Log::error("FundingRequest #{$review->id}: Failed to send email to borrower ({$borrowerEmail}). Error: {$e->getMessage()}", [
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);
            }

            // --- 2. PARTNER (if non-host partner) ---
            if ($isNonHostPartner) {
                if ($sendsEmail && !$partnerEmail) {
                    Log::warning("FundingRequest #{$review->id}: Skipped partner email - Non-host partner ID {$partner->id} ({$partner->company_name}) has no email or contact_person_email configured.");
                }

                try {
                    $notifier->send('partner.funding_request.status_changed', $partner, $notificationData, mail: $sendsEmail && $partnerEmail
                        ? ($isApproved ? new PartnerFundingRequestApproved($review, $partner, false) : new UnderwritingStatusUpdated($review, UnderwritingStatusUpdated::AUDIENCE_PARTNER))->to($partnerEmail)
                        : null);

                    if ($sendsEmail && $partnerEmail) {
                        Log::info(__('funding_request.logs.dispatched_to_partner', [
                            'id' => $review->id,
                            'mailable' => $isApproved ? 'PartnerFundingRequestApproved' : 'UnderwritingStatusUpdated',
                            'email' => $partnerEmail,
                            'partner_id' => $partner->id,
                        ]));
                    }
                } catch (Throwable $e) {
                    Log::error("FundingRequest #{$review->id}: Failed to send email to partner ({$partnerEmail}). Error: {$e->getMessage()}", [
                        'partner_id' => $partner->id,
                        'exception'  => $e->getMessage(),
                        'trace'      => $e->getTraceAsString(),
                    ]);
                }
            } else {
                Log::info("FundingRequest #{$review->id}: Skipped separate partner email because borrower belongs directly to Host (Partner ID: " . ($partner?->id ?? 'Host') . ", is_host: 1).");
            }

            // --- 3. HOST COPY (a non-host partner's request was approved) ---
            if ($isNonHostPartner && $isApproved) {
                $mailHost = $hostEmail && $hostEmail !== $partnerEmail && $hostEmail !== $borrowerEmail;

                try {
                    $notifier->send('host.funding_request.approved', $hostPartner, [
                        'id' => $review->id,
                        'partner' => $partner->company_name ?: $partner->full_name,
                    ], mail: $mailHost ? (new PartnerFundingRequestApproved($review, $hostPartner, true))->to($hostEmail) : null);

                    if ($mailHost) {
                        Log::info(__('funding_request.logs.dispatched_to_host', ['id' => $review->id, 'email' => $hostEmail]));
                    }
                } catch (Throwable $e) {
                    Log::error("FundingRequest #{$review->id}: Failed to send notification to Host ({$hostEmail}). Error: {$e->getMessage()}", [
                        'exception' => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]);
                }
            }

            // In-platform activity notification for borrower
            if ($data['status'] === FundingRequest::STATUS_APPROVED) {
                app(ActivityLogService::class)->logSystemEvent(
                    FundingRequest::class,
                    $review->id,
                    $review->partner_id,
                    $review->borrower_id,
                    "Funding Request Approved",
                    "Your funding request has been approved. Please proceed to complete your funding application.",
                    'notification',
                    [
                        'is_internal' => false,
                        'visible_to_borrower' => true,
                        'email_sent' => !empty($borrowerEmail),
                        'approval_time' => Carbon::now()->toDateTimeString(),
                        'email_delivery_status' => 'Dispatched'
                    ],
                    ActivityLog::VISIBILITY_EVERYONE
                );
            }
        }

        $oldStatusFormatted = ucwords(strtolower($oldStatus));
        $newStatusFormatted = ucwords(strtolower($data['status']));

        if ($oldStatus === $data['status']) {
            $title = "Funding Request Review Note Updated";
            $description = "Review note / rejection reason was updated.";
        } else {
            $title = "Funding Request Status Changed: {$oldStatusFormatted} → {$newStatusFormatted}";
            $description = "Status updated to: " . ucfirst(strtolower($data['status']));
        }

        app(ActivityLogService::class)->logSystemEvent(
            FundingRequest::class,
            $review->id,
            $review->partner_id,
            $review->borrower_id,
            $title,
            $description,
            ActivityLog::LOG_TYPE_FUNDING_REQUEST_STATUS_UPDATE,
            ['status' => $data['status'], 'reject_reason' => $data['reject_reason'] ?? null]
        );

        return ApiResponse::success($review, __('funding_request.status_updated_successfully'));
    }

    /**
     * Assign Lender to Funding Request
     */
    public function assignLender(array $data)
    {
        $authUser = Auth::user();

        $review = FundingRequest::where('id', $data['id'])
            ->when(isset($authUser->partner) && $authUser->partner->is_host == 0, function ($q) use ($authUser) {
                $q->where('partner_id', $authUser->partner_id);
            })->first();

        if (!$review) {
            return ApiResponse::error(__('funding_request.funding_request_not_found'));
        }

        // Past its application's editable statuses the lender assigned is fixed.
        if (! $review->canAssignLender()) {
            return ApiResponse::error(__('application.lender_locked', ['status' => $review->fundingApplication->status]), 422);
        }

        $review->lender_ids = $data['lender_ids'] ?? [];
        $review->assign_lender = !empty($data['lender_ids']) ? $data['lender_ids'][0] : null;
        $review->save();

        // Named as on screen, "Lender Name - Legal Name" (R2 BUG-008).
        $lenderNames = Lender::whereIn('id', $review->lender_ids)->get()->map(fn (Lender $lender) => $lender->displayName())->all();
        $lendersStr = empty($lenderNames) ? 'None' : implode(', ', $lenderNames);

        app(ActivityLogService::class)->logSystemEvent(
            FundingRequest::class,
            $review->id,
            $review->partner_id,
            $review->borrower_id,
            // The lender goes in the title: it is what tells one assignment from the next.
            (count($lenderNames) > 1 ? 'Lenders Assigned: ' : 'Lender Assigned: ') . $lendersStr,
            "Assigned Lender(s): " . $lendersStr,
            'update',
            [
                'lender_ids' => $review->lender_ids,
                'is_internal' => true
            ],
            ActivityLog::VISIBILITY_INTERNAL
        );

        return ApiResponse::success($review, "Lenders assigned successfully");
    }
}
