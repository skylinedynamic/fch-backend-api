<?php

namespace App\Services\Borrower\Funding;

use App\Http\Resources\Borrower\Funding\FundingRequestResource;
use App\Mail\FundingRequest\UnderwritingSubmitted;
use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Services\Borrower\Funding\FundingReadinessService;
use App\Traits\PaginatesApi;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\ActivityLog\ActivityLog;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\Borrower\Document\DocumentService;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\BorrowerDocType;

class FundingRequestService
{
    use PaginatesApi;

    protected $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

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

        $latestRequest = FundingRequest::where('borrower_id', $borrower->id)
            ->where('funding_sequence_id', $data['funding_sequence_id'])
            ->orderBy('id', 'desc')
            ->first();

        // An approved request that already produced an application can only be
        // re-opened once that application has left the borrower's hands - with a
        // lender, under contract, funded, closed or denied (see
        // config/funding_application.php `statuses.allows_new_request`). That
        // round is settled either way, so a fresh request starts a new one.
        //
        // While the application is still being reviewed here - On Hold, Draft,
        // Pending, Underwriting, Verification, Approved - a second request for the
        // same funding option would run a parallel pipeline for the same borrower
        // against the same lenders, which is what this refuses.
        if ($latestRequest && $latestRequest->status === FundingRequest::STATUS_APPROVED) {
            $latestApplication = FundingApplication::where('funding_request_id', $latestRequest->id)
                ->orderBy('id', 'desc')
                ->first();

            if ($latestApplication && ! $latestApplication->allowsNewFundingRequest()) {
                throw new Exception(__('funding_request.application_already_in_progress', [
                    'status' => $latestApplication->status,
                ]));
            }
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
            $lenderIds = array_values(array_unique($lenderIds));
        }

        $data['borrower_id'] = $borrower->id;
        $data['partner_id'] = $borrower->partner_id;
        $data['status'] = FundingRequest::STATUS_PENDING;
        $finalLenderIds = $data['lender_ids'] ?? $lenderIds;
        if (is_array($finalLenderIds)) {
            $finalLenderIds = array_values(array_unique($finalLenderIds));
        }
        $data['lender_ids'] = $finalLenderIds;

        if ($latestRequest && in_array($latestRequest->status, [FundingRequest::STATUS_REJECTED, FundingRequest::STATUS_IN_PROGRESS, FundingRequest::STATUS_ACCEPTED])) {
            $data['reject_reason'] = null;
            $data['approved_by'] = null;
            $data['approved_at'] = null;
            $latestRequest->update($data);
            $fundingRequest = $latestRequest;
            $fundingRequest->wasRecentlyCreated = false;
        } else {
            $fundingRequest = FundingRequest::create($data);
        }
        if ($fundingRequest->wasRecentlyCreated || $fundingRequest->status === FundingRequest::STATUS_PENDING) {
            $hostPartner = Partner::where('is_host', 1)->first();
            $borrowerPartner = $borrower->partner;
            
            $emailsToNotify = [];

            if ($hostPartner && !empty($hostPartner->email)) {
                $emailsToNotify[] = $hostPartner->email;
            } else {
                Log::warning('UnderwritingSubmitted email not sent to host: No host partner found or email is empty.');
            }

            if ($borrowerPartner && $borrowerPartner->is_host == 0 && !empty($borrowerPartner->email)) {
                if (!in_array($borrowerPartner->email, $emailsToNotify)) {
                    $emailsToNotify[] = $borrowerPartner->email;
                }
            }

            foreach ($emailsToNotify as $email) {
                Log::info("Attempting to send UnderwritingSubmitted email to {$email}.");
                try {
                    Mail::to($email)->send(new UnderwritingSubmitted($fundingRequest));
                    Log::info("UnderwritingSubmitted email sent successfully to {$email}.");
                } catch (\Exception $e) {
                    Log::error("Failed to send UnderwritingSubmitted email to {$email}.", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }

        if ($fundingRequest->wasRecentlyCreated) {
            app(ActivityLogService::class)->logSystemEvent(
                FundingRequest::class,
                $fundingRequest->id,
                $fundingRequest->partner_id,
                $fundingRequest->borrower_id,
                'Funding Request Submitted',
                'Borrower submitted a new funding request for ' . ($fundingOption ? $fundingOption->name : 'funding sequence'),
                ActivityLog::LOG_TYPE_FUNDING_REQUEST_CREATE
            );
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
            ->with(['borrower', 'fundingSequence', 'reviewer', 'fundingApplication'])
            ->where('funding_requests.borrower_id', $borrower->id);

        if (!empty($filters['status'])) {
            $query->where('funding_requests.status', $filters['status']);
        }

        if (!empty($filters['funding_sequence_id'])) {
            $query->where('funding_requests.funding_sequence_id', $filters['funding_sequence_id']);
        }

        if (!empty($filters['application_status'])) {
            if ($filters['application_status'] === FundingRequest::APPLICATION_STATUS_NOT_STARTED) {
                $query->where('funding_requests.status', FundingRequest::STATUS_APPROVED)
                      ->doesntHave('fundingApplication');
            } else {
                $query->whereHas('fundingApplication', function ($q) use ($filters) {
                    $q->where('status', $filters['application_status']);
                });
            }
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('funding_requests.funding_type', 'like', "%{$search}%")
                  ->orWhere('funding_requests.use_of_funds', 'like', "%{$search}%")
                  ->orWhere('funding_requests.requested_amount', 'like', "%{$search}%")
                  ->orWhere('funding_requests.status', 'like', "{$search}%")
                  ->orWhereRaw("DATE_FORMAT(funding_requests.created_at, '%b %d, %Y') LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("DATE_FORMAT(funding_requests.created_at, '%M %d, %Y') LIKE ?", ["%{$search}%"])
                  ->orWhere('funding_sequencings.amount_range', 'like', "%{$search}%")
                  ->orWhere('funding_sequencings.name', 'like', "%{$search}%")
                  ->orWhereHas('fundingApplication', function($qApp) use ($search) {
                      $qApp->where('status', 'like', "{$search}%");
                  });

                if (stripos(FundingRequest::APPLICATION_STATUS_NOT_STARTED, $search) !== false) {
                    $q->orWhere(function($sq) {
                        $sq->where('funding_requests.status', FundingRequest::STATUS_APPROVED)
                           ->doesntHave('fundingApplication');
                    });
                }
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

        // Fetch unique funding sequences used by this borrower to populate the dropdown
        $fundingTypes = FundingSequencing::whereIn('id', function($q) use ($borrower) {
            $q->select('funding_sequence_id')
              ->from('funding_requests')
              ->where('borrower_id', $borrower->id);
        })->get(['id', 'name']);

        $stats = [
            'total_reviews' => FundingRequest::where('borrower_id', $borrower->id)->count(),
            'approved' => FundingRequest::where('borrower_id', $borrower->id)
                ->whereIn('status', [FundingRequest::STATUS_APPROVED, FundingRequest::STATUS_ACCEPTED])
                ->count(),
            'pending' => FundingRequest::where('borrower_id', $borrower->id)
                ->whereIn('status', [FundingRequest::STATUS_PENDING, 'Pending'])
                ->count(),
            'rejected' => FundingRequest::where('borrower_id', $borrower->id)
                ->where('status', FundingRequest::STATUS_REJECTED)
                ->count(),
        ];

        return $this->respondWithPaginationResource(
            $paginator,
            FundingRequestResource::class,
            'funding requests retrieved successfully.',
            200,
            [
                'funding_types' => $fundingTypes,
                'stats' => $stats,
            ]
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

        $review = FundingRequest::with(['borrower', 'fundingSequence', 'reviewer', 'fundingApplication'])
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

    public function uploadDocument(array $data)
    {
        $user = Auth::user();
        $borrower = $user->borrower;

        // 1. Create the BorrowerDocument(s)
        $docType = BorrowerDocType::find($data['document_type_id']);
        if (!$docType) {
            throw new Exception("Document type not found.");
        }
        
        $documentResponse = $this->documentService->createBorrowerDocuments([
            'files' => $data['files'],
            'document_type_id' => $data['document_type_id']
        ]);
        
        $documents = $documentResponse->getData(true)['data'] ?? [];

        // 2. Find any pending/rejected/in_progress/accepted FundingRequest for this sequence
        $fundingRequest = FundingRequest::where('borrower_id', $borrower->id)
            ->where('funding_sequence_id', $data['funding_sequence_id'])
            ->whereIn('status', [
                FundingRequest::STATUS_PENDING, 
                FundingRequest::STATUS_REJECTED, 
                FundingRequest::STATUS_IN_PROGRESS, 
                FundingRequest::STATUS_ACCEPTED
            ])
            ->first();

        // 3. Update the documents_uploaded array if funding request exists
        if ($fundingRequest && $docType) {
            $uploaded = $fundingRequest->documents_uploaded ?? [];
            if (!in_array($docType->name, $uploaded)) {
                $uploaded[] = $docType->name;
                $fundingRequest->documents_uploaded = $uploaded;
                $fundingRequest->save();
            }
        }

        return $documents;
    }

    public function deleteDocument($documentId, array $data)
    {
        $user = Auth::user();
        $borrower = $user->borrower;

        // 1. Delete the BorrowerDocument
        $document = BorrowerDocument::where('borrower_id', $borrower->id)->find($documentId);
        if (!$document) {
            throw new Exception("Document not found.");
        }
        
        $docTypeName = $document->documentType ? $document->documentType->name : null;
        $document->delete();

        // 2. Check if other documents of the same type still exist
        if ($docTypeName) {
            $otherDocsCount = BorrowerDocument::where('borrower_id', $borrower->id)
                ->whereHas('documentType', function($q) use ($docTypeName) {
                    $q->where('name', $docTypeName);
                })
                ->count();

            // 3. If none exist, remove from FundingRequest
            if ($otherDocsCount === 0) {
                $fundingRequest = FundingRequest::where('borrower_id', $borrower->id)
                    ->where('funding_sequence_id', $data['funding_sequence_id'])
                    ->whereIn('status', [
                        FundingRequest::STATUS_PENDING, 
                        FundingRequest::STATUS_REJECTED, 
                        FundingRequest::STATUS_IN_PROGRESS, 
                        FundingRequest::STATUS_ACCEPTED
                    ])
                    ->first();

                if ($fundingRequest) {
                    $uploaded = $fundingRequest->documents_uploaded ?? [];
                    if (($key = array_search($docTypeName, $uploaded)) !== false) {
                        unset($uploaded[$key]);
                        // Re-index array
                        $fundingRequest->documents_uploaded = array_values($uploaded);
                        $fundingRequest->save();
                    }
                }
            }
        }
    }
}
