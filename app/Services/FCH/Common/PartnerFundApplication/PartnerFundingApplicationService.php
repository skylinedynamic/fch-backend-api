<?php

namespace App\Services\FCH\Common\PartnerFundApplication;

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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
class PartnerFundingApplicationService
{

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
            $req = FundingRequest::find($fundingRequestId);
            if ($req) {
                $borrowerId = $req->borrower_id;
            }
        }

        $query = FundingApplication::where('borrower_id', $borrowerId)
            ->with('borrower', 'coborrowers', 'fundingRequest.fundingSequence', 'fundingSequence')
            ->whereIn('status', [FundingApplication::ON_HOLD, FundingApplication::DRAFT]);

        if ($fundingRequestId) {
            $query->where('funding_request_id', $fundingRequestId);
        }

        $fundingInfo = $query->latest()->first();

        if ($fundingInfo) {
            return $fundingInfo;
        } else {
            return Borrower::where('id', $borrowerId)->first();
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
                $existing = FundingApplication::find($matchId);
                if ($existing) {
                    $borrowerId = $existing->borrower_id;
                }
            } else if ($fundingReqId) {
                $req = FundingRequest::find($fundingReqId);
                if ($req) {
                    $borrowerId = $req->borrower_id;
                }
            }

            if (!$borrowerId) {
                throw new \Exception("Could not determine borrower ID.");
            }

            $data['status'] = $status ?? FundingApplication::DRAFT;
            $data['is_partner_prepared'] = true;
            $data['borrower_id'] = $borrowerId;
            $data['partner_id'] = auth()->user()->partner_id ?? auth()->id(); // Use partner_id if it exists, otherwise fallback

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
                $query = FundingApplication::where('borrower_id', $borrowerId)
                    ->whereIn('status', [FundingApplication::ON_HOLD, FundingApplication::DRAFT]);

                if ($fundingReqId) {
                    $query->where('funding_request_id', $fundingReqId);
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
                // User specifies no updated_by for partner creation
            }



            $fundingApplication = FundingApplication::updateOrCreate(
                ['id' => $matchId],
                $data
            );


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
                    // Send to Partner
                    if ($fundingApplication->partner && $fundingApplication->partner->email) {
                        try {
                            Mail::to($fundingApplication->partner->email)->send(new FundingApplicationSubmittedMail($fundingApplication, $fundingApplication->partner->id));
                        } catch (\Throwable $e) {
                            Log::error('Failed to send funding application submission email to partner', [
                                'application_id' => $fundingApplication->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    // Send to Host
                    $host = Partner::host();
                    if ($host && $host->id != $fundingApplication->partner_id && $host->email) {
                        try {
                            Mail::to($host->email)->send(new FundingApplicationSubmittedMail($fundingApplication, $host->id));
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
        $application = FundingApplication::findOrFail($fundingApplicationId);
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
        $application = FundingApplication::findOrFail($fundingApplicationId);
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
            ->firstOrFail();

        // Delete from storage
        if (Storage::disk('public')->exists($document->path)) {
            Storage::disk('public')->delete($document->path);
        }

        // Delete record
        return $document->forceDelete();
    }
}
