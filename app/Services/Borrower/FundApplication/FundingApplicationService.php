<?php

namespace App\Services\Borrower\FundApplication;

use App\Exceptions\FundingApplicationLockedException;
use App\Services\FCH\Common\Notification\Notifier;
use App\Helpers\ApiResponse;
use App\Mail\Borrower\FundingApplicationSubmittedMail;
use App\Models\Borrower\Borrower;
use App\Models\User\User;

use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\Borrower\BorrowerDocument;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Application\FundingApplicationAuditLogger;
use App\Services\FCH\Common\Application\FundingApplicationEditGuard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Mail\Partner\BorrowerSubmittedFundingApplicationMail;
class FundingApplicationService
{
    public function __construct(
        private FundingApplicationEditGuard $editGuard,
        private FundingApplicationAuditLogger $auditLogger
    ) {
    }

    /**
     * Get Business Info for the authenticated user.
     *
     * @param int $userId
     * @return mixed
     */
    public function getBorrowerInfo()
    {
        return Borrower::where('id', auth()->user()->entity_id)->first();
    }

    public function getFundingInfo()
    {
        $fundingRequestId = request()->input('funding_request_id') ?? request()->input('fundingRequestId');
        $query = FundingApplication::where('borrower_id', auth()->user()->entity_id)
            ->with('borrower', 'coborrowers', 'fundingRequest.fundingSequence', 'fundingSequence');

        if ($fundingRequestId) {
            $query->where('funding_request_id', $fundingRequestId);
        } else {
            $query->whereIn('status', [FundingApplication::ON_HOLD, FundingApplication::DRAFT]);
        }

        $fundingInfo = $query->latest()->first();

        if ($fundingInfo) {
            return $fundingInfo;
        } else {
            return Borrower::where('id', auth()->user()->entity_id)->first();
        }
    }


    /**
     * Save Funding Application data.
     *
     * @param array $data
     * @return mixed
     */
    public function saveFundingApplication(array $data, $status = null)
    {
        try {
            $borrowerId = auth()->user()->entity_id;

            $data['status'] = $status ?? FundingApplication::ON_HOLD;
            $data['borrower_id'] = $borrowerId;
            $data['partner_id'] = auth()->user()->partner_id;

            if (empty($data['funding_request_id'])) {
                $data['funding_request_id'] = request()->input('funding_request_id') 
                    ?? request()->input('fundingRequestId') 
                    ?? request()->route('fundingRequestId');
            }

            $matchId = $data['id'] ?? null;
            $existing = null;

            // 1. Try to find the application by ID if provided
            if (!empty($matchId)) {
                $existing = FundingApplication::find($matchId);
            }

            // 2. Determine funding_request_id (use existing if not provided in payload)
            if (empty($data['funding_request_id'])) {
                $data['funding_request_id'] = request()->input('funding_request_id') 
                    ?? request()->input('fundingRequestId') 
                    ?? request()->route('fundingRequestId');
            }

            // 3. If we STILL don't have an existing application, try to find one by request ID or ON_HOLD status
            if (empty($existing)) {
                $fundingReqId = $data['funding_request_id'] ?? null;
                $query = FundingApplication::where('borrower_id', $borrowerId);

                if ($fundingReqId) {
                    $query->where('funding_request_id', $fundingReqId);
                } else {
                    $query->where('status', FundingApplication::ON_HOLD);
                }

                $existing = $query->latest()->first();
                if ($existing) {
                    $matchId = $existing->id;
                }
            }

            // 4. If we STILL don't have a funding_request_id, and we are creating a NEW application, fallback to latest approved request
            if (empty($data['funding_request_id'])) {
                if ($existing) {
                    $data['funding_request_id'] = $existing->funding_request_id;
                } else {
                    $latestReq = FundingRequest::where('borrower_id', $borrowerId)
                        ->where('status', FundingRequest::STATUS_APPROVED)
                        ->latest()
                        ->first();
                    if ($latestReq) {
                        $data['funding_request_id'] = $latestReq->id;
                        if (empty($data['funding_sequence_id'])) {
                            $data['funding_sequence_id'] = $latestReq->funding_sequence_id;
                        }
                    }
                }
            }

            // 5. Setup sequence ID defaults based on the resolved request
            $req = null;
            if (!empty($data['funding_request_id'])) {
                $req = FundingRequest::with('fundingSequence')->find($data['funding_request_id']);
                if ($req && empty($data['funding_sequence_id'])) {
                    $data['funding_sequence_id'] = $req->funding_sequence_id;
                }
            }

            if (empty($data['funding_sequence_id']) && request()->has('funding_sequence_id')) {
                $data['funding_sequence_id'] = request()->input('funding_sequence_id');
            }

            $matchId = $data['id'] ?? null;
            $existing = null;
            if (empty($matchId)) {
                $fundingReqId = $data['funding_request_id'] ?? null;
                $query = FundingApplication::where('borrower_id', $borrowerId);

                if ($fundingReqId) {
                    $query->where('funding_request_id', $fundingReqId);
                } else {
                    $query->whereIn('status', [FundingApplication::ON_HOLD, FundingApplication::DRAFT]);
                }

                $existing = $query->latest()->first();
                if ($existing) {
                    $matchId = $existing->id;
                }
            } else {
                $existing = FundingApplication::find($matchId);
            }

            $isNew = empty($existing);

            if ($isNew) {
                $data['created_by'] = auth()->id();
            } else {
                $data['updated_by'] = auth()->id();
            }



            // What this save is allowed to do to an application that already
            // exists - and whether it costs the borrower their signature - is
            // decided in one place for both portals. See FundingApplicationEditGuard.
            $changedFields = [];
            $changes = [];
            $requiresResign = false;
            $previousStatus = $existing->status ?? null;

            if (!$isNew && $existing) {
                // Saving a step of an application that already exists never moves
                // it on by itself; only an explicit status (submitting) does.
                if (empty($status)) {
                    $data['status'] = $existing->status;
                }

                try {
                    $decision = $this->editGuard->applyEditRules($existing, $data, $status);
                } catch (FundingApplicationLockedException $e) {
                    $this->auditLogger->logBlockedEdit($existing, FundingApplicationAuditLogger::ACTOR_BORROWER);
                    throw $e;
                }

                $data = $decision['data'];
                $changedFields = $decision['changed_fields'];
                $changes = $decision['changes'];
                $requiresResign = $decision['requires_resign'];
            }

            $fundingApplication = FundingApplication::updateOrCreate(
                ['id' => $matchId],
                $data
            );

            if ($requiresResign) {
                $this->auditLogger->logEditRequiringResign(
                    $fundingApplication,
                    $changedFields,
                    FundingApplicationAuditLogger::ACTOR_BORROWER,
                    $previousStatus ?? '',
                    $changes
                );
            }


            if (isset($data['has_co_borrower']) && $data['has_co_borrower']) {
                $coBorrowerIds = [];
                if (!empty($data['co_borrowers'])) {
                    foreach ($data['co_borrowers'] as $coBorrower) {
                        $record = FundingApplicationCoBorrower::updateOrCreate(['id' => $coBorrower['id'] ?? null], [
                            'funding_application_id' => $fundingApplication->id,
                            'first_name' => $coBorrower['first_name'] ?? null,
                            'last_name' => $coBorrower['last_name'] ?? null,
                            'title_role' => $coBorrower['role'] ?? null,
                            'ownership_percentage' => $coBorrower['ownership'] ?? null,
                            'email' => $coBorrower['email'] ?? null,
                            'mobile_phone' => $coBorrower['mobile_phone'] ?? null,
                            'home_phone' => $coBorrower['home_phone'] ?? null,
                            'date_of_birth' => $coBorrower['date_of_birth'] ?? null,
                            'ssn' => $coBorrower['ssn'] ?? null,
                            'street_address' => $coBorrower['street_address'] ?? null,
                            'city' => $coBorrower['city'] ?? null,
                            'state' => $coBorrower['state'] ?? null,
                            'zip_code' => $coBorrower['zip_code'] ?? null,
                            'drivers_license_number' => $coBorrower['drivers_license_number'] ?? null,
                            'drivers_license_state' => $coBorrower['drivers_license_state'] ?? null,
                        ]);
                        $coBorrowerIds[] = $record->id;
                    }
                }

                // Sync: Delete co-borrowers that are not in the provided data
                FundingApplicationCoBorrower::where('funding_application_id', $fundingApplication->id)
                    ->whereNotIn('id', $coBorrowerIds)
                    ->forceDelete();
            } else if (isset($data['has_co_borrower']) && !$data['has_co_borrower']) {
                // If has_co_borrower is specifically set to false, remove all co-borrowers
                FundingApplicationCoBorrower::where('funding_application_id', $fundingApplication->id)->forceDelete();
            }

            if ($fundingApplication) {
                $this->createFundingApplicationDocumentDir($fundingApplication->id);
            }

            // Send email if submitted
            if ($fundingApplication->status === FundingApplication::PENDING) {
                try {
                    // Bell + email. The email only where there is an address, as before.
                    $notifier = app(Notifier::class);
                    $bellData = [
                        'id' => $fundingApplication->id,
                        'borrower' => personName($fundingApplication->borrower?->first_name, $fundingApplication->borrower?->middle_name, $fundingApplication->borrower?->last_name),
                    ];

                    // Send to Borrower
                    if ($fundingApplication->borrower) {
                        try {
                            $email = $fundingApplication->borrower->email;
                            $notifier->send('borrower.funding_application.submitted', $fundingApplication->borrower, $bellData,
                                mail: $email ? (new FundingApplicationSubmittedMail($fundingApplication))->to($email) : null);
                        } catch (\Throwable $e) {
                            Log::error('Failed to send funding application submission email to borrower', [
                                'application_id' => $fundingApplication->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    // Send to Partner
                    if ($fundingApplication->partner) {
                        try {
                            $email = $fundingApplication->partner->email;
                            $notifier->send('partner.funding_application.submitted', $fundingApplication->partner, $bellData,
                                mail: $email ? (new BorrowerSubmittedFundingApplicationMail($fundingApplication, $fundingApplication->partner->id))->to($email) : null);
                        } catch (\Throwable $e) {
                            Log::error('Failed to send funding application submission email to partner', [
                                'application_id' => $fundingApplication->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    // Send to Host
                    $host = Partner::host();
                    if ($host && $host->id != $fundingApplication->partner_id) {
                        try {
                            $notifier->send('partner.funding_application.submitted', $host, $bellData,
                                mail: $host->email ? (new BorrowerSubmittedFundingApplicationMail($fundingApplication, $host->id))->to($host->email) : null);
                        } catch (\Throwable $e) {
                            Log::error('Failed to send funding application submission email to host', [
                                'application_id' => $fundingApplication->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to process funding application emails', [
                        'application_id' => $fundingApplication->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $fundingApplication;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Create funding application document directory and copy borrower documents
     *
     * @param int $fundingApplicationId
     * @return string
     */
    public function createFundingApplicationDocumentDir($fundingApplicationId)
    {
        $borrowerId = auth()->user()->entity_id;

        $existingDocuments = FundingApplicationDocument::where('funding_application_id', $fundingApplicationId)->count();

        if ($existingDocuments > 0) {
            return true;
        }

        $documents = BorrowerDocument::where('borrower_id', $borrowerId)->get();
        $baseDestinationDir = "funding-application/{$borrowerId}/FA-{$fundingApplicationId}";

        if (!Storage::disk('public')->exists($baseDestinationDir)) {
            Storage::disk('public')->makeDirectory($baseDestinationDir);
        }

        foreach ($documents as $document) {
            $rawFilePath = $document->getRawOriginal('file_path');

            if (!$rawFilePath) {
                continue;
            }

            $sourcePath = "borrowers/{$borrowerId}/{$rawFilePath}";

            if (Storage::disk('public')->exists($sourcePath)) {
                // Copy only the file to base folder, no subdirectories
                $fileName = basename($rawFilePath);
                $destinationPath = "{$baseDestinationDir}/{$fileName}";

                Storage::disk('public')->copy($sourcePath, $destinationPath);

                FundingApplicationDocument::create([
                    'funding_application_id' => $fundingApplicationId,
                    'borrower_id' => $borrowerId,
                    'borrower_doc_type_id' => $document->document_type_id ?? null,
                    'name' => $document->file_name ?? basename($rawFilePath),
                    'path' => $destinationPath,
                    'disk' => 'public',
                    'original_name' => $document->file_name ?? basename($rawFilePath),
                    'extension' => pathinfo($sourcePath, PATHINFO_EXTENSION),
                    'mime' => Storage::disk('public')->mimeType($sourcePath),
                    'size' => Storage::disk('public')->size($sourcePath),
                ]);
            }
        }

        return true;
    }

    /**
     * Upload multiple documents for a funding application.
     *
     * @param array $data
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadDocument(array $data)
    {
        $documents = [];
        $borrowerId = auth()->user()->entity_id;
        $fundingApplicationId = $data['funding_application_id'];
        $destinationDir = "funding-application/{$borrowerId}/FA-{$fundingApplicationId}";

        foreach ($data['files'] as $index => $file) {
            try {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $path = $file->store($destinationDir, 'public');
                    $name = $file->getClientOriginalName();
                    $originalName = $file->getClientOriginalName();
                    $extension = $file->getClientOriginalExtension();
                    $mime = $file->getMimeType();
                    $size = $file->getSize();
                } else {
                    // It's a string path
                    $sourcePath = $this->normalizePath($borrowerId, $file);

                    if (!Storage::disk('public')->exists($sourcePath)) {
                        throw new \Exception("File does not exist: {$sourcePath}");
                    }

                    $fileName = basename($sourcePath);
                    $destinationPath = "{$destinationDir}/{$fileName}";

                    // Ensure destination directory exists
                    if (!Storage::disk('public')->exists($destinationDir)) {
                        Storage::disk('public')->makeDirectory($destinationDir);
                    }

                    Storage::disk('public')->copy($sourcePath, $destinationPath);

                    $path = $destinationPath;
                    $name = $fileName;
                    $originalName = $fileName;
                    $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
                    $mime = Storage::disk('public')->mimeType($sourcePath);
                    $size = Storage::disk('public')->size($sourcePath);
                }

                $documents[] = FundingApplicationDocument::create([
                    'funding_application_id' => $fundingApplicationId,
                    'borrower_id' => $borrowerId,
                    'borrower_doc_type_id' => $data['document_type_id'],
                    'name' => $name,
                    'path' => $path,
                    'disk' => 'public',
                    'original_name' => $originalName,
                    'extension' => $extension,
                    'mime' => $mime,
                    'size' => $size,
                ]);
            } catch (\Throwable $e) {

                \Log::error('Funding application document upload failed', [
                    'user_id' => auth()->id(),
                    'file' => is_string($file) ? $file : (is_object($file) ? $file->getClientOriginalName() : 'unknown'),
                    'error' => $e->getMessage(),
                ]);

                throw new \RuntimeException($e->getMessage());
            }
        }

        return ApiResponse::success(
            \App\Http\Resources\Borrower\FundApplication\FundingApplicationDocumentResource::collection($documents),
            __('borrower.document_uploaded_successfully'),
            201
        );
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
     * Delete a funding application document.
     *
     * @param int $id
     * @return bool
     * @throws \Exception
     */
    public function deleteDocument($id)
    {
        $borrowerId = auth()->user()->entity_id;

        $document = FundingApplicationDocument::where('id', $id)
            ->where('borrower_id', $borrowerId)
            ->firstOrFail();

        // Delete from storage
        if (Storage::disk('public')->exists($document->path)) {
            Storage::disk('public')->delete($document->path);
        }

        // Delete record
        return $document->forceDelete();
    }
}
