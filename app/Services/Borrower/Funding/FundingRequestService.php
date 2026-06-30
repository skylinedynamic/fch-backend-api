<?php

namespace App\Services\Borrower\Funding;

use App\Http\Resources\Borrower\Funding\FundingRequestResource;
use App\Mail\FundingRequest\UnderwritingSubmitted;
use App\Models\Funding\FundingSequencing;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Services\Borrower\Funding\FundingReadinessService;
use App\Traits\PaginatesApi;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FundingRequestService
{
    use PaginatesApi;

    /**
     * Store a new funding request.
     *
     * @param array $data
     * @return array
     */
    public function store(array $data)
    {
        $user = Auth::user();
        $borrower = $user->borrower;

        $existingPending = FundingRequest::where('borrower_id', $borrower->id)
            ->where('funding_sequence_id', $data['funding_sequence_id'])
            ->where('status', FundingRequest::STATUS_PENDING)
            ->first();

        if ($existingPending) {
            throw new Exception("Already applied. Please wait for approval or rejection.");
        }

        $fundingOption = FundingSequencing::find($data['funding_sequence_id']);
        $lenderIds = [];
        if ($fundingOption) {
            $readinessService = app(FundingReadinessService::class);
            $missingDocs = $readinessService->getMissingDocuments($fundingOption, $borrower);
            
            if (!empty($missingDocs)) {
                throw new Exception("Please upload all required documents before submitting.");
            }

            // Calculate matched lenders
            $lenders = $fundingOption->lenderVisibilities;
            foreach ($lenders as $lenderSeq) {
                $status = $lenderSeq->getEngineStatusForBorrower($borrower);
                if ($status !== FundingSequencing::ENGINE_STATUS_HIDDEN) {
                    $lenderIds[] = $lenderSeq->lender_id;
                }
            }
            if (empty($lenderIds)) {
                $lenderIds = $lenders->pluck('lender_id')->toArray();
            }
        }

        $data['borrower_id'] = $borrower->id;
        $data['partner_id'] = $borrower->partner_id;
        $data['status'] = FundingRequest::STATUS_PENDING;
        $data['lender_ids'] = $data['lender_ids'] ?? $lenderIds;

        $fundingRequest = FundingRequest::updateOrCreate(
            [
                'borrower_id' => $borrower->id,
                'funding_sequence_id' => $data['funding_sequence_id']
            ],
            $data
        );

        if ($fundingRequest->wasRecentlyCreated || $fundingRequest->status === FundingRequest::STATUS_PENDING) {
            $hostPartner = Partner::where('is_host', 1)->first();
            
            if (!$hostPartner) {
                Log::warning('UnderwritingSubmitted email not sent: No host partner found (is_host = 1).');
            } elseif (empty($hostPartner->email)) {
                Log::warning("UnderwritingSubmitted email not sent: Host partner (ID: {$hostPartner->id}) does not have an email address set.");
            } else {
                Log::info("Attempting to send UnderwritingSubmitted email to host partner (ID: {$hostPartner->id}) at {$hostPartner->email}.");
                try {
                    Mail::to($hostPartner->email)
                        ->send(new UnderwritingSubmitted($fundingRequest));
                    Log::info('UnderwritingSubmitted email sent successfully.');
                } catch (\Exception $e) {
                    Log::error('Failed to send UnderwritingSubmitted email.', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }

        $fundingRequest->loadMissing(['borrower', 'fundingSequence', 'reviewer']);
        $request = request();
        return (new FundingRequestResource($fundingRequest))->toArray($request);
    }

    /**
     * Get a list of funding requests for the authenticated borrower.
     *
     * @param array $filters
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(array $filters)
    {
        $user = Auth::user();
        $borrower = $user->borrower;

        if (!$borrower) {
            throw new Exception("Borrower profile not found for this user.");
        }

        $query = FundingRequest::query()
            ->select('funding_requests.*')
            ->leftJoin('funding_sequencings', 'funding_requests.funding_sequence_id', '=', 'funding_sequencings.id')
            ->with(['borrower', 'fundingSequence', 'reviewer'])
            ->where('funding_requests.borrower_id', $borrower->id);

        if (!empty($filters['status'])) {
            $query->where('funding_requests.status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('funding_requests.funding_type', 'like', "%{$search}%")
                  ->orWhere('funding_requests.use_of_funds', 'like', "%{$search}%")
                  ->orWhere('funding_requests.requested_amount', 'like', "%{$search}%")
                  ->orWhere('funding_requests.status', 'like', "%{$search}%")
                  ->orWhereRaw("DATE_FORMAT(funding_requests.created_at, '%b %d, %Y') LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("DATE_FORMAT(funding_requests.created_at, '%M %d, %Y') LIKE ?", ["%{$search}%"])
                  ->orWhere('funding_sequencings.amount_range', 'like', "%{$search}%")
                  ->orWhere('funding_sequencings.name', 'like', "%{$search}%");
            });
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortField, ['id', 'created_at', 'requested_amount', 'status', 'funding_type', 'use_of_funds'])) {
            $query->orderBy("funding_requests.{$sortField}", $sortOrder);
        } elseif ($sortField === 'amount_range') {
            $query->orderBy('funding_sequencings.amount_range', $sortOrder);
        } else {
            $query->orderBy('funding_requests.id', 'desc');
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        
        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            FundingRequestResource::class,
            'funding requests retrieved successfully.'
        );
    }

    /**
     * Get details of a specific funding request.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = Auth::user();
        $borrower = $user->borrower;

        if (!$borrower) {
            throw new Exception("Borrower profile not found for this user.");
        }

        $review = FundingRequest::with(['borrower', 'fundingSequence', 'reviewer'])
            ->where('borrower_id', $borrower->id)
            ->where('id', $id)
            ->first();

        if (!$review) {
            throw new Exception("funding request not found.");
        }

        return response()->json([
            'success' => true,
            'data' => new FundingRequestResource($review),
            'message' => 'funding request retrieved successfully.'
        ]);
    }
}
