<?php

namespace App\Services\Borrower\Document;

use App\Exceptions\DocumentInUseException;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Services\FCH\Common\Notification\Notifier;
use App\Helpers\ApiResponse;
use App\Http\Resources\Global\Document\BorrowerDocTypeResource;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Mail\Borrower\BorrowerDocumentsUploadedMail;
use App\Models\Borrower\Borrower;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocumentService
{

    public function listForUser(Request $request)
    {
        $borrowerId = Auth::user()->entity_id;

        return BorrowerDocument::query()
            ->ownedBy($borrowerId)
            ->withoutCreditReports()
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('document_type_id');
    }

    /**
     * Create multiple BorrowerDocument records from a list of file paths.
     */
    public function createBorrowerDocuments(array $data)
    {
        $documents = [];
        $user = Auth::user();

        foreach ($data['files'] as $index => $path) {
            try {
                $docName = $data['document_name'][$index] ?? basename($path);
                $documents[] = $this->createDocument($user->entity_id, $path, $docName, $data);
            } catch (\Throwable $e) {
                Log::error(__('borrower_validation.upload_failed'), [
                    'user_id' => $user->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException($e->getMessage());
            }
        }

        if (!empty($documents)) {
            try {
                $recipients = [];
                $borrower = Borrower::with('partner')->find($user->entity_id);
                // Add partner email if exists
                if ($borrower->partner && $borrower->partner->email) {
                    $recipients[] = $borrower->partner->email;
                }

                // Add configured doc email
                $docEmail = config('app.mail_to_doc');
                if (!empty($docEmail)) {
                    $recipients[] = $docEmail;
                }

                if ($borrower->partner || !empty($recipients)) {

                    try {
                        // Bell to the borrower's partner; the email, as before,
                        // to the partner's address and the documents inbox.
                        app(Notifier::class)->send('partner.documents.uploaded', $borrower->partner, [
                            'borrower_id' => $borrower->id,
                            'borrower' => personName($borrower->first_name, $borrower->middle_name, $borrower->last_name),
                            'count' => count($documents),
                        ], mail: $recipients ? (new BorrowerDocumentsUploadedMail($user, $documents))->to($recipients) : null);
                    } catch (\Throwable $e) {
                        Log::error('Failed to send document upload notification', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Failed to send document upload notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ApiResponse::success($documents, __('borrower.upload_success'), 201);
    }

    /**
     * Create individual borrower document record
     */
    private function createDocument(int $borrowerId, $file, string $docName, array $data): BorrowerDocument
    {
        $size = null;
        $borrowerPath = $this->normalizePath($borrowerId, $file);

        if (Storage::disk('public')->exists($borrowerPath)) {
            try {
                $size = $this->formatSize(Storage::disk('public')->size($borrowerPath));
            } catch (\Exception $e) {
                // Ignore size error
            }
        } elseif (Storage::disk('public')->exists(ltrim($file, '/'))) {
            try {
                $size = $this->formatSize(Storage::disk('public')->size(ltrim($file, '/')));
            } catch (\Exception $e) {
            }
        }

        $dbPath = str_replace("borrowers/{$borrowerId}/", "", $borrowerPath);

        $createData = [
            'borrower_id' => $borrowerId,
            'document_name' => $docName, // This is for metadata, model has file_name
            'file_name' => $docName,
            'document_type_id' => $data['document_type_id'] ?? null,
            'file_path' => $dbPath,
            'file_size' => $size,
            'status' => BorrowerDocument::STATUS_UPLOADED,
            'created_by' => Auth::id(),
        ];

        return BorrowerDocument::create($createData);
    }

    /**
     * Normalize file path for borrowers
     */
    private function normalizePath(int $borrowerId, $file): string
    {
        // If it's an UploadedFile object, put it in borrowers/{id}/
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            return "borrowers/{$borrowerId}/" . $file->getClientOriginalName();
        }

        // If it's a string, ensure it starts with borrowers/{id}/
        $path = ltrim($file, '/');
        if (!str_starts_with($path, "borrowers/{$borrowerId}/")) {
            return "borrowers/{$borrowerId}/" . $path;
        }

        return $path;
    }

    /**
     * Remove a document by id for the authenticated borrower.
     */
    public function removeById(int $id): bool
    {
        try {
            $doc = BorrowerDocument::query()
                ->ownedBy(auth()->user()->entity_id)
                ->whereKey($id)
                ->first();

            if (!$doc) {
                return false;
            }

            $this->assertNotNeededByOpenRequest($doc);

            $doc->deleted_by = Auth::id();
            $doc->save();
            $doc->delete();

            return true;
        } catch (DocumentInUseException $e) {
            throw $e;
        } catch (\Throwable $e) {

            Log::error('Document removal failed', [
                'user_id' => Auth::id(),
                'document_id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(__('borrower.document_delete_failed'));
        }
    }

    /**
     * Refuse to remove an Accepted document that is the borrower's only usable
     * copy of a type an open funding request or unfinished application needs
     * (R2 BUG-055): the option would then report it missing. A replacement can
     * be uploaded first, after which the old copy is free to go.
     *
     * Open: a request that is Pending, In Progress or Approved, or any request
     * whose application is not yet Funded, Closed (Completed) or Denied.
     */
    private function assertNotNeededByOpenRequest(BorrowerDocument $doc): void
    {
        if ($doc->status !== BorrowerDocument::STATUS_ACCEPTED || !$doc->document_type_id) {
            return;
        }

        $hasAnotherCopy = BorrowerDocument::query()
            ->ownedBy($doc->borrower_id)
            ->where('document_type_id', $doc->document_type_id)
            ->where('status', '!=', BorrowerDocument::STATUS_REJECTED)
            ->whereKeyNot($doc->id)
            ->exists();

        if ($hasAnotherCopy) {
            return;
        }

        $finished = [FundingApplication::FUNDED, FundingApplication::CLOSED_COMPLETED, FundingApplication::DENIED];
        $unfinishedApplicationRequestIds = FundingApplication::where('borrower_id', $doc->borrower_id)
            ->whereNotNull('funding_request_id')
            ->get(['funding_request_id', 'status'])
            ->reject(fn ($application) => in_array(FundingApplication::canonicalStatus($application->status), $finished, true))
            ->pluck('funding_request_id');

        $needingIds = FundingRequest::where('borrower_id', $doc->borrower_id)
            ->where(fn ($q) => $q
                ->whereIn('status', [FundingRequest::STATUS_PENDING, FundingRequest::STATUS_IN_PROGRESS, FundingRequest::STATUS_APPROVED])
                ->orWhereIn('id', $unfinishedApplicationRequestIds))
            ->with('fundingSequence:id,require_documents_uploaded,allowed_documents')
            ->orderBy('id')
            ->get()
            ->filter(fn ($request) => $request->fundingSequence?->require_documents_uploaded
                && in_array((int) $doc->document_type_id, array_map('intval', (array) $request->fundingSequence->allowed_documents), true))
            ->pluck('id')
            ->all();

        if ($needingIds !== []) {
            throw new DocumentInUseException(
                __('borrower.document_in_use', ['requests' => implode(', ', array_map(fn ($id) => "#{$id}", $needingIds))]),
                $needingIds
            );
        }
    }

    /**
     * Get Document Types
     */
    public function documentTypes()
    {
        try {
            $documentTypes = BorrowerDocType::orderBy('sort_order', 'asc')->get();

            return ApiResponse::success(
                BorrowerDocTypeResource::collection($documentTypes),
                __('borrower.document_types_retrieved')
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(__('borrower.document_types_fetch_failed'));
        }
    }

    /**
     * Update Document Status
     */
    public function updateStatus(int $id, array $data)
    {
        try {
            $doc = BorrowerDocument::find($id);
            if (!$doc) {
                return ApiResponse::notFound(__('borrower.document_not_found'));
            }

            $updateData = [
                'status' => $data['status'],
                'updated_by' => Auth::id(),
            ];

            if ($data['status'] === BorrowerDocument::STATUS_REJECTED) {
                $updateData['rejection_reason'] = $data['rejection_reason'] ?? null;
            } else {
                $updateData['rejection_reason'] = null;
            }

            $doc->update($updateData);

            return ApiResponse::success($doc, __('borrower.document_status_updated'));
        } catch (\Throwable $e) {
            Log::error('Document status update failed', [
                'document_id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(__('borrower.document_status_update_failed'));
        }
    }

    /**
     * Format file size.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }
}