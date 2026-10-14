<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Borrower;

use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\Borrower\BorrowerDocTypeResource;
use App\Services\FCH\Common\Borrower\BorrowerDocumentsChecklistService;
use App\Models\Borrower\Borrower;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BorrowerDocumentsChecklistController extends Controller
{
    protected $checklistService;

    public function __construct(BorrowerDocumentsChecklistService $checklistService)
    {
        $this->checklistService = $checklistService;
    }

    /**
     * Get all document types with checked/unchecked status for a specific borrower
     *
     * @param int $borrowerId
     * @return JsonResponse
     */
    public function getChecklist(string $borrowerId): JsonResponse
    {
      
        try {
            // Check if borrower exists
            // Own partner's clients only (any for the host's team).
            $borrower = Borrower::visibleTo(auth()->user())->find((int)$borrowerId);
           
            if (!$borrower) {
                return response()->json([
                    'success' => false,
                    'message' => __('borrower.error.not_found'),
                ], 404);
            }

            $documentTypes = $this->checklistService->getChecklistWithStatus((int)$borrowerId);
          
            return response()->json([
                'success' => true,
                'message' => __('borrower.checklist.retrieved_success'),
                'data' => BorrowerDocTypeResource::collection($documentTypes),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => __('borrower.checklist.retrieved_failed'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save/Update document checklist for a borrower
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveChecklist(Request $request, string $borrowerId): JsonResponse
    {
        // Check if borrower exists
        // Own partner's clients only (any for the host's team).
        $borrower = Borrower::visibleTo(auth()->user())->find((int)$borrowerId);
       
        if (!$borrower) {
            return response()->json([
                'success' => false,
                'message' => __('borrower.error.not_found'),
            ], 404);
        }

        // If no documentation data is provided, return early with success
        if (!$request->has('checked_doc_type_ids')) {
            return response()->json([
                'success' => true,
                'message' => __('borrower.checklist.no_data_provided'),
            ], 200);
        }

        $validated = $request->validate([
            'checked_doc_type_ids' => 'nullable|array',
            'checked_doc_type_ids.*' => 'integer|exists:borrower_doc_types,id',
        ]);

        try {
            $ids = $validated['checked_doc_type_ids'] ?? [];
            
            $result = $this->checklistService->saveChecklist(
                $borrower->id,
                auth()->user()->partner_id,
                $ids
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'error' => $result['error'] ?? null,
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['data'],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => __('borrower.checklist.save_failed'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

