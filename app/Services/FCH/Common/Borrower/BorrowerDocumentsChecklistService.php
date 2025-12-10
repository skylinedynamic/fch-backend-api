<?php

namespace App\Services\FCH\Common\Borrower;

use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocumentsChecklist;
use Illuminate\Support\Facades\DB;

class BorrowerDocumentsChecklistService
{
    /**
     * Get all document types with checked status for a specific borrower
     *
     * @param int $borrowerId
     * @return \Illuminate\Support\Collection
     */
    public function getChecklistWithStatus(int $borrowerId)
    {
        // Get all document types ordered by sort_order
        $docTypes = BorrowerDocType::orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        // Get all checklist items for this borrower (with current_status = true)
        $checkedItems = BorrowerDocumentsChecklist::where('borrower_id', $borrowerId)
            ->where('current_status', true)
            ->whereNotNull('borrower_doc_types_id')
            ->get()
            ->keyBy('borrower_doc_types_id');

        // Map document types with checked status
        return $docTypes->map(function ($docType) use ($checkedItems) {
            $isChecked = $checkedItems->has($docType->id);
            
            return [
                'id' => $docType->id,
                'name' => $docType->name,
                'sort_order' => $docType->sort_order,
                'current_status' => $isChecked, // boolean: true if checked, false if not
            ];
        });
    }

    /**
     * Save/Update document checklist items for a borrower
     *
     * @param int $borrowerId
     * @param int $partnerId
     * @param array $checkedDocTypeIds - Array of document type IDs that are checked
     * @return array
     */
    public function saveChecklist(int $borrowerId, ?int $partnerId, array $checkedDocTypeIds)
    {
        DB::beginTransaction();
        
        try {
            // Get all document types to ensure we handle both checked and unchecked status
            $allDocTypes = BorrowerDocType::orderBy('sort_order', 'asc')->get();

            $savedItems = [];
            foreach ($allDocTypes as $docType) {
                // Determine if this doc type is in the checked list
                $isChecked = in_array($docType->id, $checkedDocTypeIds);
                
                $checklist = BorrowerDocumentsChecklist::updateOrCreate(
                    [
                        'borrower_id' => $borrowerId,
                        'borrower_doc_types_id' => $docType->id
                    ],
                    [
                        'partner_id' => $partnerId,
                        'document_name' => $docType->name,
                        'current_status' => $isChecked
                    ]
                );

                $savedItems[] = $checklist;
            }

            DB::commit();

            return [
                'success' => true,
                'message' => __('borrower.checklist.saved_success'),
                'data' => $savedItems,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            
            return [
                'success' => false,
                'message' => __('borrower.checklist.save_failed'),
                'error' => $e->getMessage(),
            ];
        }
    }
}
