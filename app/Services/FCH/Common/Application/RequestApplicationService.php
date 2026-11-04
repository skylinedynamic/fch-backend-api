<?php

namespace App\Services\FCH\Common\Application;

use App\Helpers\ApiResponse;
use App\Http\Resources\Borrower\MyApplication\FundingApplicationResource;
use App\Http\Resources\Borrower\MyApplication\PreQualificationApplicationResource;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource as ApplicationFundingApplicationResource;
use App\Http\Resources\FCH\Common\Application\PreQualificationRequestResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\DownloadHistory;
use App\Models\Borrower\FundingApplication;
use App\Services\FCH\Common\Notification\Notifier;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Commission\Commission;
use App\Models\Common\AppConfiguration;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Lender\Lender;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\Application\SignatureCertificateService;
use App\Services\FCH\Common\FundingRequest\FundingRequestService;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RequestApplicationService
{
    use PaginatesApi;

    /**
     * How long a PENDING evaluation may go without touching the application
     * before it is treated as abandoned. Worst case for a live run (three calls,
     * each with retries at the client timeout) stays well inside this.
     */
    private const AI_RUN_STALE_MINUTES = 15;

    public function __construct(
        protected ActivityLogService $activityLogService,
        protected PreQualificationAiService $preQualificationAiService
    ) {}

    /**
     * Get the authenticated user's partner ID
     *
     * @return int
     */
    private function getAuthenticatedPartnerId(): int
    {
        return Auth::user()->partner_id;
    }

    /**
     * Get PreQualification Applications List
     */
    public function getPreQualificationList(array $filters)
    {
        $partnerId = $this->getAuthenticatedPartnerId();
        
        $query = PreQualificationRequest::query()
        ->where('status', '!=', PreQualificationRequest::ON_HOLD)
        ->when(Auth::user()->user_type == User::PARTNER, function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        });

        if(isset($filters['status']) && !empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if(isset($filters['start_date']) && !empty($filters['start_date'])) {
            $query->whereRaw('DATE(COALESCE(updated_at, created_at)) >= ?', [$filters['start_date']]);
        }

        if(isset($filters['end_date']) && !empty($filters['end_date'])) {
            $query->whereRaw('DATE(COALESCE(updated_at, created_at)) <= ?', [$filters['end_date']]);
        }

        if(isset($filters['funding_type']) && !empty($filters['funding_type'])) {
            $query->where('purpose_of_funding', $filters['funding_type']);
        }

        // Apply Search
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->search($search);
        }

        // Apply Sorting & Advanced Filters using QueryFilter if available, or manual simple sorting
        // Most recently updated first by default, so an older draft that is
        // submitted or edited today shows at the top rather than by its old id.
        $sortField = $filters['sort_field'] ?? 'updated_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $advancedFilters = $filters['filters'] ?? [];


        if($sortField == 'funding_type') {
            $sortField = 'purpose_of_funding';
        }elseif($sortField == 'requested_amount') {
            $sortField = 'funding_requested_amount';
        }elseif($sortField == 'date' || $sortField == 'id') {
            $sortField = 'updated_at';
        }elseif($sortField == 'full_name') {
            $sortField = 'first_name';
        }


        // updated_at is nullable, so fall back to created_at rather than letting a
        // row with no updated_at sink to the end of the list.
        $sortByUpdatedAt = $sortField == 'updated_at';
        if ($sortByUpdatedAt) {
            $query->orderByRaw("COALESCE(updated_at, created_at) {$sortOrder}");
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $advancedFilters,
             [
                'skip_sorting' => $sortByUpdatedAt,
                'aliases' => [
                    'purpose_of_funding' => 'funding_type',
                    'funding_requested_amount' => 'requested_amount',
                    'updated_at' => 'date',
                    'first_name' => 'full_name',
                ],
            ]
        )->orderBy('id', 'desc');

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator, 
            PreQualificationApplicationResource::class,
            __('application.pre_qualification_retrieved')
        );
    }

    /**
     * Get Funding Applications List
     */
    public function getFundingApplicationList(array $filters)
        {
            $authUser = Auth::user();
            
            $query = FundingApplication::query()
                ->with(['borrower', 'fundingSequence', 'fundingRequest'])
                // ->where('status', '!=', FundingApplication::ON_HOLD)
                ->when($authUser->user_type == User::PARTNER, function($query) use($authUser){
                    $query->where('partner_id', $authUser->partner_id);
                });

            // Scope to one client, for the Funding Applications tab on the
            // client record. The funding request list already supported this;
            // applications did not, so a client's own applications could only be
            // found by searching the global list.
            if (!empty($filters['borrower_id'])) {
                $query->where('borrower_id', $filters['borrower_id']);
            }

            // Apply Search
            if (!empty($filters['search'])) {
                $search = trim((string) $filters['search']);
                $query->search($search);
            }

            // Status filter: accepted but never applied before, so every status
            // showed the same rows (BUG-048).
            if (!empty($filters['status'])) {
                $query->where('funding_applications.status', $filters['status']);
            }

            // Date Range: whole days in the viewer's timezone - the DATE column
            // shows created_at there - turned into UTC bounds for the stored
            // value. It used to be ignored altogether.
            $timezone = $this->validTimezone($filters['timezone'] ?? null);
            if (!empty($filters['start_date'])) {
                $query->where('funding_applications.created_at', '>=', Carbon::parse($filters['start_date'], $timezone)->startOfDay()->utc());
            }
            if (!empty($filters['end_date'])) {
                $query->where('funding_applications.created_at', '<=', Carbon::parse($filters['end_date'], $timezone)->endOfDay()->utc());
            }

            $sortField = $filters['sort_field'] ?? 'id';
            $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $advancedFilters = $filters['filters'] ?? [];

            // DATE shows created_at ("20 Oct 2026"); `date` is not a column, so
            // sorting on it never changed the order.
            if ($sortField === 'date') {
                $sortField = 'created_at';
            }

            // Sorting on status follows the lifecycle (key 1-26), not the
            // alphabet - "Approved" is not the first thing that happens.
            $sortByStatusKey = $sortField === 'status';

            if ($sortByStatusKey) {
                $query->orderByStatusKey($sortOrder)->orderBy('id', 'desc');
            }

            // An On Hold application has no amount of its own yet and shows its
            // funding request's amount (BUG-136), so it sorts by that one too
            // (BUG-153) - same fallback as requestedAmountOrRequest().
            $sortByShownAmount = $sortField === 'requested_amount';

            if ($sortByShownAmount) {
                $query->orderByRaw(
                    "COALESCE(funding_applications.requested_amount, (SELECT funding_requests.requested_amount FROM funding_requests WHERE funding_requests.id = funding_applications.funding_request_id)) {$sortOrder}"
                );
            }

            $query = QueryFilter::apply(
                $query,
                $sortField,
                $sortOrder,
                $advancedFilters,
                ($sortByStatusKey || $sortByShownAmount) ? ['skip_sorting' => true] : []
            );

            // Equal values (two applications on the same day) keep one order
            // across pages.
            $query->orderBy('funding_applications.id', 'desc');

            $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
            $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

            $paginator = $this->paginateBuilderWith($query, $perPage, $page);

            return $this->respondWithPaginationResource(
                $paginator,
                FundingApplicationResource::class,
                __('application.funding_application_retrieved')
            );
        }

    /** The viewer's timezone if it is a real one, else the app's. */
    private function validTimezone(?string $timezone): string
    {
        return $timezone && in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : config('app.timezone');
    }
    /**
     * Get Pre-Qualification Application By ID
     */
    public function getPreQualificationById($id)
    {
        $partnerId = $this->getAuthenticatedPartnerId();
        
        $application = PreQualificationRequest::with('borrower')
           ->when(Auth::user()->user_type == User::PARTNER, function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        })
        ->where('id',$id)->first();

        if($application == null){
            return ApiResponse::error(__('application.pre_qualification_not_found'));
        }
        return ApiResponse::success(new PreQualificationRequestResource($application));
    }

    /**
     * Run AI Pre-Qualification Underwriting Analysis By ID
     */
    public function runAiPreQualification($id)
    {
        // Manually triggering an evaluation is host-only: it produces the internal
        // memo, risk flags and lender routing, which a partner may never see.
        // (The automatic run on borrower submit is a queued system job and is not
        // subject to this check.)
        $user = Auth::user();
        $isHost = (bool) ($user?->partner?->is_host ?? false);
        $isPartner = ($user?->entity_name === User::PARTNER || $user?->entity_type === Partner::class || $user?->user_type == User::PARTNER || $user?->user_type === 'Admin');

        if (!$isHost || !$isPartner) {
            return ApiResponse::error(__('application.pre_qualification_not_found'), 403);
        }

        $partnerId = $this->getAuthenticatedPartnerId();

        $application = PreQualificationRequest::with('borrower')
            ->when(!$isHost, function ($q) use ($partnerId) {
                $q->where('partner_id', $partnerId);
            })
            ->where('id', $id)->first();

        if ($application == null) {
            return ApiResponse::error(__('application.pre_qualification_not_found'));
        }

        // Claimed atomically so two clicks (or two staff) cannot start the same
        // evaluation twice. A PENDING run is only taken over once it has gone quiet
        // for longer than any live run can take — the process running it was killed
        // — so a stuck file can still be re-run instead of staying PENDING forever.
        $claimed = PreQualificationRequest::where('id', $application->id)
            ->where(function ($q) {
                $q->whereNull('gpt_status')
                    ->orWhere('gpt_status', '!=', PreQualificationRequest::GPT_PENDING)
                    ->orWhere('updated_at', '<', now()->subMinutes(self::AI_RUN_STALE_MINUTES));
            })
            ->update([
                'gpt_status' => PreQualificationRequest::GPT_PENDING,
                'gpt_generated_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return ApiResponse::error(__('application.pre_qualification_ai_already_running'), 409);
        }

        // The evaluation makes up to three OpenAI calls, which regularly runs past
        // the gateway timeout in front of the API; run inline, the browser got a
        // network error with no response. So the response is sent first (status
        // PENDING) and the evaluation runs once it has been delivered — the same
        // approach as the borrower submit. The details page polls until it settles.
        $userId = Auth::id();
        app()->terminating(function () use ($application, $userId) {
            @set_time_limit(0);

            try {
                $this->preQualificationAiService->evaluate($application->fresh('borrower'), $userId);
            } catch (\Throwable $e) {
                if ($failed = PreQualificationRequest::find($application->id)) {
                    $failed->applyUnderwritingOutcome(false);
                    $failed->gpt_status = PreQualificationRequest::GPT_FAILED;
                    $failed->gpt_generated_at = now();
                    $failed->save();
                }

                Log::error('Pre-qualification AUE re-run failed', [
                    'pre_qualification_request_id' => $application->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return ApiResponse::success([
            'application' => new PreQualificationRequestResource($application->fresh('borrower')),
        ], __('application.pre_qualification_ai_started'));
    }

    /**
     * Get Funding Application By ID
     */
    public function getFundingApplicationById($id)
    {
        $authUser = Auth::user();

        $application = FundingApplication::with('commission','partner','borrower','coborrowers','fundingDocuments','fundingDocuments.docType', 'downloadHistories', 'downloadHistories.user', 'fundingSequence', 'fundingRequest')
            ->reviewableBy($authUser)
            ->where('funding_applications.id', $id)->first();

        if($application == null){
            return ApiResponse::error(__('application.funding_application_not_found'));
        }
        //return ApiResponse::success($application);
        return ApiResponse::success(new ApplicationFundingApplicationResource($application));
    }

    /**
     * Get Funding Application Audit History
     */
    public function getAuditHistory($id, array $filters)
    {
        if (!FundingApplication::reviewableBy(Auth::user())->whereKey($id)->exists()) {
            return ApiResponse::error(__('application.funding_application_not_found'));
        }

        $perPage = $filters['per_page'] ?? 10;
        if ($perPage == -1 || $perPage == 'All') {
            $items = DownloadHistory::where('funding_application_id', $id)->get();
            $perPage = $items->count() > 0 ? $items->count() : 10;
        }
        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page']) ? max(1, (int) $filters['per_page']) : (is_numeric($perPage) ? max(1, (int) $perPage) : 1000000);
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $query = DownloadHistory::with('user:id,name,email,user_type')
            ->where('funding_application_id', $id);

        if (!empty($filters['search'])) {
            $query->search($filters['search'], $filters['timezone'] ?? null);
        }

        if (!empty($filters['sort_by'])) {
            $sortField = $filters['sort_by'];
            $sortDir = (!empty($filters['sort_dir']) && strtolower($filters['sort_dir']) === 'asc') ? 'asc' : 'desc';
            $query->orderBy($sortField, $sortDir);
        } else {
            $query->orderBy('downloaded_at', 'desc');
        }

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            \App\Http\Resources\FCH\Common\Application\DownloadHistoryResource::class,
            __('application.audit_history_retrieved')
        );
    }

    /**
     * Update Pre-qualification Application Status
     */
    public function preQualificationStatusUpdate(array $data)
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $application = PreQualificationRequest::with('borrower')
            ->reviewableBy(Auth::user())
            ->where('pre_qualification_requests.id', $data['id'])->first();

        if($application == null){
            return ApiResponse::error(__('application.pre_qualification_not_found'));
        }

        $oldStatus = $application->status;
        $application->status = $data['status'];
        if (isset($data['status_note'])) {
            $application->status_note = $data['status_note'];
        }
        $application->save();

        // The client hears of each real status change in their bell (BUG-063).
        if ($oldStatus !== $application->status && $application->borrower) {
            app(Notifier::class)->send('borrower.pre_qualification.status_changed', $application->borrower, [
                'id' => $application->id,
                'status' => $application->status,
                'note' => filled($data['status_note'] ?? null) ? ' Note: ' . $data['status_note'] : '',
            ]);
        }

        return ApiResponse::success(new PreQualificationRequestResource($application), __('application.status_updated'));
    }

    /**
     * Assign a lender to a funding application (host's team only - see
     * AssignFundingApplicationLenderRequest). One with a funding request shares
     * the request's lender, so it is assigned there, as on the funding request
     * page; one without (converted from a loan application) keeps its own.
     * At any status (decision 2026-11-03), as on the funding request page.
     */
    public function assignFundingApplicationLender(int $id, array $lenderIds)
    {
        $application = FundingApplication::with('borrower', 'fundingRequest')
            ->reviewableBy(Auth::user())
            ->whereKey($id)->first();

        if ($application == null) {
            return ApiResponse::notFound(__('application.funding_application_not_found'));
        }

        // Past the editable statuses the lender assigned is fixed.
        if (! $application->canAssignLender()) {
            return ApiResponse::error(__('application.lender_locked', ['status' => $application->status]), 422);
        }

        $lenderIds = array_values(array_map('intval', $lenderIds));

        if ($application->funding_request_id && $application->fundingRequest) {
            // The request's own assignment, logged on the request as its page does.
            app(FundingRequestService::class)->assignLender([
                'id' => $application->funding_request_id,
                'lender_ids' => $lenderIds,
            ]);
            $application->load('fundingRequest');
        } else {
            $application->forceFill([
                'lender_ids' => $lenderIds,
                'assign_lender' => $lenderIds[0] ?? null,
            ])->save();
        }

        $lenderNames = Lender::whereIn('id', $lenderIds)->pluck('name')->implode(', ') ?: 'None';
        app(ActivityLogService::class)->logSystemEvent(
            FundingApplication::class,
            $application->id,
            $application->partner_id,
            $application->borrower_id,
            __('application.activity_lender_assigned_title', ['lenders' => $lenderNames]),
            __('application.activity_lender_assigned_description', ['lenders' => $lenderNames]),
            'update',
            ['lender_ids' => $lenderIds, 'is_internal' => true],
            ActivityLog::VISIBILITY_INTERNAL
        );

        return ApiResponse::success(
            new ApplicationFundingApplicationResource($application->fresh(['borrower', 'fundingRequest'])),
            __('application.lender_assigned')
        );
    }

    /**
     * Update Funding Application Status
     */
    public function fundingApplicationStatusUpdate(array $data)
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $application = FundingApplication::with('borrower', 'fundingRequest')
            ->reviewableBy(Auth::user())
            ->where('funding_applications.id', $data['funding_application_id'])->first();

        if($application == null){
            return ApiResponse::error(__('application.funding_application_not_found'));
        }

        // Matched to Lender ... Closed (Completed) only once a lender is assigned.
        if ($data['status'] !== $application->status
            && FundingApplication::statusRequiresLender($data['status'])
            && ! $application->hasAssignedLender()) {
            return ApiResponse::error(__('application.lender_required_for_status', ['status' => $data['status']]), 422);
        }

        $oldStatus = $application->status;

        if($data['status'] == FundingApplication::CLOSED_COMPLETED){
            $application->total_funded_amount = $data['funded_amount'];
            // The day in the closer's zone, not the UTC one (BUG-120).
            $application->funded_date = todayInRequestTimezone();
        }

        $application->status = $data['status'];
        if (isset($data['status_note'])) {
            $application->status_note = $data['status_note'];
        }
        $application->save();

        if ($oldStatus !== $data['status'] || isset($data['status_note'])) {
            $oldStatusFormatted = ucwords(str_replace('_', ' ', $oldStatus));
            $newStatusFormatted = ucwords(str_replace('_', ' ', $data['status']));
            
            if ($oldStatus === $data['status']) {
                $title = __('application.activity_note_updated_title');
                $description = __('application.activity_note_updated_description');
            } else {
                $title = __('application.activity_status_changed_title', ['from' => $oldStatusFormatted, 'to' => $newStatusFormatted]);
                $description = __('application.activity_status_changed_description', ['to' => $newStatusFormatted]);
            }

            if (isset($data['status_note'])) {
                $description .= "\n" . __('application.activity_status_note', ['note' => $data['status_note']]);
            }

            app(ActivityLogService::class)->logSystemEvent(
                FundingApplication::class,
                $application->id,
                $application->partner_id,
                $application->borrower_id,
                $title,
                $description,
                ActivityLog::LOG_TYPE_FUNDING_APPLICATION_STATUS_UPDATE,
                ['visible_to_borrower' => true, 'visible_to_partner' => true],
                ActivityLog::VISIBILITY_EVERYONE
            );
        }

        if ($application->status == FundingApplication::CLOSED_COMPLETED && $application->total_funded_amount > 0) {
            $commission = Commission::where('funding_application_id', $application->id)->first();
            if ($commission) {
                // Ensure we use the correct base amount
                $baseAmount = $application->total_funded_amount;
                $totalCommissionAmount = ($baseAmount * $commission->total_commission) / 100;
                
                $adminCommissionAmount = $totalCommissionAmount;
                $partnerCommissionAmount = 0;

                if ($commission->partner_commission > 0) {
                    $partnerCommissionAmount = ($totalCommissionAmount * $commission->partner_commission) / 100;
                    $adminCommissionAmount = $totalCommissionAmount - $partnerCommissionAmount;
                }

                $commission->update([
                    'total_commission_amount' => $totalCommissionAmount,
                    'admin_commission_amount' => $adminCommissionAmount,
                    'partner_commission_amount' => $partnerCommissionAmount,
                    'commission_status' => Commission::STATUS_PROCESSING,
                    'payment_status' => Commission::PAYMENT_STATUS_UNPAID,
                ]);
            }
        }

        return ApiResponse::success(new ApplicationFundingApplicationResource($application), __('application.status_updated'));
    }
    /**
     * Get Pre-Qualification Card Summary
     */
    public function getPreQualificationCardSummary()
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $baseQuery = PreQualificationRequest::where('status', '!=', PreQualificationRequest::ON_HOLD)
            ->when(Auth::user()->user_type == User::PARTNER, function ($q) use($partnerId) {
                $q->where('partner_id', $partnerId);
            });

        return ApiResponse::success([
            'total_applications' => (clone $baseQuery)->count(),
            'approved' => (clone $baseQuery)->where('status', PreQualificationRequest::APPROVED)->count(),
            'pending' => (clone $baseQuery)->where('status', PreQualificationRequest::PENDING)->count(),
            'approved_amount' => currencyFormat((clone $baseQuery)->where('status', PreQualificationRequest::APPROVED)->sum('funding_requested_amount')),
        ]);
    }

    /**
     * Get Funding Application Card Summary
     */
    public function getFundingApplicationCardSummary()
    {
        $authUser = Auth::user();

        $baseQuery = FundingApplication::
        // ::where('status', '!=', FundingApplication::ON_HOLD)
            when($authUser->user_type == User::PARTNER, function($query) use($authUser){
                $query->where('partner_id', $authUser->partner_id);
            });

        return ApiResponse::success([
            'total_applications' => (clone $baseQuery)->count(),
            'approved' => (clone $baseQuery)->where('status', FundingApplication::CLOSED_COMPLETED)->count(),
            'pending' => (clone $baseQuery)->where('status', FundingApplication::PENDING)->count(),
            'approved_amount' => currencyFormat((clone $baseQuery)->where('status', FundingApplication::CLOSED_COMPLETED)->sum('requested_amount')),
        ]);
    }

}
