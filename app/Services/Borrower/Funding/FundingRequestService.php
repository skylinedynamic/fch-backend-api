<?php

namespace App\Services\Borrower\Funding;

use App\Services\FCH\Common\Notification\Notifier;
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

        // Every submission is a new funding request. Earlier requests and
        // applications for the same funding option - pending, rejected, on hold,
        // in review or settled - neither block it nor are reused or changed.

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

        $fundingRequest = FundingRequest::create($data);

        if ($fundingRequest->wasRecentlyCreated) {
            $hostPartner = Partner::where('is_host', 1)->first();
            $borrowerPartner = $borrower->partner;
            
            // Who is told: the host, and the borrower's own partner when that is
            // not the host. Each gets the bell; the email goes to each distinct
            // address, as before.
            $toNotify = [];
            $mailed = [];

            if ($hostPartner) {
                $toNotify[] = $hostPartner;
            }
            if (!$hostPartner || empty($hostPartner->email)) {
                Log::warning('UnderwritingSubmitted email not sent to host: No host partner found or email is empty.');
            }

            if ($borrowerPartner && $borrowerPartner->is_host == 0) {
                $toNotify[] = $borrowerPartner;
            }

            $bellData = [
                'id' => $fundingRequest->id,
                'borrower' => personName($borrower->first_name, $borrower->middle_name, $borrower->last_name),
            ];

            foreach ($toNotify as $recipient) {
                $email = !empty($recipient->email) && !in_array($recipient->email, $mailed, true) ? $recipient->email : null;
                $mailed[] = $email;

                if ($email) {
                    Log::info("Attempting to send UnderwritingSubmitted email to {$email}.");
                }
                try {
                    app(Notifier::class)->send('partner.funding_request.submitted', $recipient, $bellData,
                        mail: $email ? (new UnderwritingSubmitted($fundingRequest))->to($email) : null);
                    if ($email) {
                        Log::info("UnderwritingSubmitted email sent successfully to {$email}.");
                    }
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
        
        // Existing funding requests are not touched: a new request sends its own
        // `documents_uploaded` when it is submitted.
        return $documentResponse->getData(true)['data'] ?? [];
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
        
        // Existing funding requests keep the documents they were submitted with.
        $document->delete();
    }
}
