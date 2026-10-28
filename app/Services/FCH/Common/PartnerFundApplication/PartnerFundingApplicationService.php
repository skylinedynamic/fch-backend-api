<?php

namespace App\Services\FCH\Common\PartnerFundApplication;

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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Mail\Partner\FundingApplicationSubmittedMail as PartnerFundingApplicationSubmittedMail;
class PartnerFundingApplicationService
{
    public function __construct(
        private FundingApplicationEditGuard $editGuard,
        private FundingApplicationAuditLogger $auditLogger
    ) {
    }

    /**
     * Where an application the partner is still preparing should sit: On Hold
     * while steps are outstanding, Draft once federal compliance - the last step -
     * is on file and it is ready to go to the borrower to sign.
     *
     * @param array $data
     * @param FundingApplication|null $existing
     * @return string
     */
    private function preparationStatus(array $data, $existing = null): string
    {
        $hasFederalCompliance = isset($data['federal_compliance_disclosures_explanation'])
            || ($existing && $existing->federal_compliance_disclosures_explanation !== null);

        return $hasFederalCompliance ? FundingApplication::DRAFT : FundingApplication::ON_HOLD;
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
        $borrowerId = request()->input('borrower_id');

        if (!$borrowerId && $fundingRequestId) {
            $req = FundingRequest::visibleTo(auth()->user())->find($fundingRequestId);
            if ($req) {
                $borrowerId = $req->borrower_id;
            }
        }

        // Only a borrower the caller may reach (own partner's; any for the host).
        if (!$borrowerId || !Borrower::visibleTo(auth()->user())->whereKey($borrowerId)->exists()) {
            return null;
        }

        $query = FundingApplication::where('borrower_id', $borrowerId)
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
            if ($borrowerId) {
                return Borrower::find($borrowerId);
            }
            return null;
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
            $fundingReqId = $data['funding_request_id'] ?? request()->input('funding_request_id') ?? request()->input('fundingRequestId');
            $matchId = $data['id'] ?? null;
            
            $borrowerId = null;
            if ($matchId) {
                $existing = FundingApplication::visibleTo(auth()->user())->find($matchId);
                if ($existing) {
                    $borrowerId = $existing->borrower_id;
                }
            } else if ($fundingReqId) {
                $req = FundingRequest::visibleTo(auth()->user())->find($fundingReqId);
                if ($req) {
                    $borrowerId = $req->borrower_id;
                }
            }

            if (!$borrowerId) {
                // Neither an application nor a request the caller may reach.
                throw new ModelNotFoundException(__('borrower.funding_request_not_found'));
            }

            // Status, the edit lock and the resign rules are all applied further
            // down, once the application this save actually targets has been
            // resolved - the lookup below can land on a different row than the
            // preliminary one used here to find the borrower.

            $data['is_partner_prepared'] = true;
            $data['borrower_id'] = $borrowerId;
            // The application belongs to the borrower's partner, whoever prepares
            // it - not the caller's (findings O17). is_partner_prepared records
            // that a partner-side user did.
            $data['partner_id'] = Borrower::whereKey($borrowerId)->value('partner_id');

            if (empty($data['funding_request_id'])) {
                $data['funding_request_id'] = request()->input('funding_request_id') 
                    ?? request()->input('fundingRequestId') 
                    ?? request()->input('id')
                    ?? request()->route('id')
                    ?? request()->route('fundingRequestId');
            }

            if (empty($data['funding_request_id'])) {
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

            $req = null;
            if (!empty($data['funding_request_id'])) {
                // The request must be this borrower's (BUG-082): otherwise the
                // application was attached to another borrower's request.
                $req = FundingRequest::with('fundingSequence')
                    ->where('borrower_id', $borrowerId)
                    ->find($data['funding_request_id']);
                if (!$req) {
                    throw new ModelNotFoundException(__('borrower.funding_request_not_found'));
                }
                if (empty($data['funding_sequence_id'])) {
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

                // Match on the funding request alone, not on "still a draft". This
                // used to filter to On Hold / Draft, which meant a save for an
                // already-submitted application found nothing and created a second
                // row - quietly sidestepping both the edit lock and the resign
                // rules below, which is exactly what they exist to prevent.
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
                $existing = FundingApplication::visibleTo(auth()->user())->findOrFail($matchId);
            }

            $isNew = empty($existing);

            if ($isNew) {
                $data['created_by'] = auth()->id();
                // User specifies no updated_by for partner creation
            }

            // The partner wizard parks an in-progress application at On Hold and
            // promotes it to Draft once the last step (federal compliance) is on
            // file, so it is ready to send to the borrower to sign. An application
            // that is already past signing keeps the status it has; only an
            // explicit status, or a resign forced below, moves it.
            $changedFields = [];
            $changes = [];
            $requiresResign = false;
            $previousStatus = $existing->status ?? null;

            if (!$isNew && $existing && empty($status)) {
                $data['status'] = in_array($existing->status, FundingApplication::preSignatureStatuses(), true)
                    ? $this->preparationStatus($data, $existing)
                    : $existing->status;
            } else {
                $data['status'] = $status ?? $this->preparationStatus($data, $existing);
            }

            if (!$isNew && $existing) {
                try {
                    // With the editor: the host's team may edit at any status.
                    $decision = $this->editGuard->applyEditRules($existing, $data, $status, auth()->user());
                } catch (FundingApplicationLockedException $e) {
                    $this->auditLogger->logBlockedEdit($existing, FundingApplicationAuditLogger::ACTOR_PARTNER);
                    throw $e;
                }

                // Not from a tab showing an older copy (R2 BUG-041).
                $this->editGuard->assertCurrent($existing, request()->input('expected_version'));

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
                // A partner changed data the borrower had already attested to, so
                // it is the borrower's signature that is now stale.
                $this->auditLogger->logEditRequiringResign(
                    $fundingApplication,
                    $changedFields,
                    FundingApplicationAuditLogger::ACTOR_PARTNER,
                    $previousStatus ?? '',
                    $changes
                );
            } elseif (! $isNew && empty($status) && $changedFields !== [] && $existing->hasBeenSubmitted()) {
                // A further change to an application that was submitted before
                // (a re-sign is already owed). It costs nothing more, but what
                // changed is still recorded. Preparing a new application for
                // the first time is not logged step by step.
                $this->auditLogger->logEdit(
                    $fundingApplication,
                    $changedFields,
                    FundingApplicationAuditLogger::ACTOR_PARTNER,
                    $changes
                );
            }


            if (isset($data['has_co_borrower']) && $data['has_co_borrower']) {
                $coBorrowerIds = [];
                if (!empty($data['co_borrowers'])) {
                    foreach ($data['co_borrowers'] as $coBorrower) {
                        // Only this application's own co-borrowers are updated
                        // by id; any other id is treated as a new co-borrower.
                        $ownCoBorrowerId = empty($coBorrower['id']) ? null : FundingApplicationCoBorrower::where('funding_application_id', $fundingApplication->id)
                            ->whereKey($coBorrower['id'])
                            ->value('id');
                        $record = FundingApplicationCoBorrower::updateOrCreate(['id' => $ownCoBorrowerId], [
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

            // Bell + email only when this save IS the submit (Attestation passes
            // the Pending status in). Re-saving a step of an application that is
            // already Pending keeps that status but submits nothing, so it must
            // not send "submitted" again to the borrower, partner and host.
            if ($status === FundingApplication::PENDING && $fundingApplication->status === FundingApplication::PENDING) {
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
                                mail: $email ? (new PartnerFundingApplicationSubmittedMail($fundingApplication, $fundingApplication->partner->id))->to($email) : null);
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
                                mail: $host->email ? (new PartnerFundingApplicationSubmittedMail($fundingApplication, $host->id))->to($host->email) : null);
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
        $application = FundingApplication::visibleTo(auth()->user())->findOrFail($fundingApplicationId);
        $borrowerId = $application->borrower_id;

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
        $fundingApplicationId = $data['funding_application_id'];
        $application = FundingApplication::visibleTo(auth()->user())->findOrFail($fundingApplicationId);
        $borrowerId = $application->borrower_id;
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
        $document = FundingApplicationDocument::where('id', $id)
            ->whereHas('fundingApplication', fn ($applications) => $applications->visibleTo(auth()->user()))
            ->firstOrFail();

        // Delete from storage
        if (Storage::disk('public')->exists($document->path)) {
            Storage::disk('public')->delete($document->path);
        }

        // Delete record
        return $document->forceDelete();
    }
}
