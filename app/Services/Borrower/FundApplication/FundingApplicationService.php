<?php

namespace App\Services\Borrower\FundApplication;

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
class FundingApplicationService
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
        $query = FundingApplication::where('borrower_id', auth()->user()->entity_id)
            ->with('borrower', 'coborrowers', 'fundingRequest.fundingSequence', 'fundingSequence')
            ->whereIn('status', [FundingApplication::ON_HOLD, FundingApplication::DRAFT]);

        if ($fundingRequestId) {
            $query->where('funding_request_id', $fundingRequestId);
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
            } else {
                $data['updated_by'] = auth()->id();
            }

            // Inherit default values from FundingRequest if creating new or if field is explicitly empty in update
            if ($req) {
                if ($isNew || (array_key_exists('requested_amount', $data) && empty($data['requested_amount']) && empty($existing?->requested_amount))) {
                    $data['requested_amount'] = $data['requested_amount'] ?? $req->requested_amount;
                }
                if ($isNew || (array_key_exists('purpose_of_funds', $data) && empty($data['purpose_of_funds']) && empty($existing?->purpose_of_funds))) {
                    $data['purpose_of_funds'] = $data['purpose_of_funds'] ?? $req->use_of_funds;
                }
                if ($isNew || (array_key_exists('approved_funding', $data) && empty($data['approved_funding']) && empty($existing?->approved_funding))) {
                    $data['approved_funding'] = $data['approved_funding'] ?? ($req->fundingSequence ? $req->fundingSequence->name : null);
                }
                if ($isNew || (array_key_exists('preferred_funding_type', $data) && empty($data['preferred_funding_type']) && empty($existing?->preferred_funding_type))) {
                    $data['preferred_funding_type'] = $data['preferred_funding_type'] ?? $req->funding_type;
                }
                if ($isNew || (array_key_exists('timeframe_needed', $data) && empty($data['timeframe_needed']) && empty($existing?->timeframe_needed))) {
                    $data['timeframe_needed'] = $data['timeframe_needed'] ?? $req->estimated_speed;
                }
            }

            // Inherit default values from Borrower if creating new or if field is explicitly empty in update
            $borrower = Borrower::find($borrowerId);
            if ($borrower) {
                // Personal Info
                if ($isNew || (array_key_exists('full_name', $data) && empty($data['full_name']) && empty($existing?->full_name))) {
                    $data['full_name'] = $data['full_name'] ?? trim($borrower->first_name . ' ' . $borrower->last_name);
                }
                if ($isNew || (array_key_exists('first_name', $data) && empty($data['first_name']) && empty($existing?->first_name))) {
                    $data['first_name'] = $data['first_name'] ?? $borrower->first_name;
                }
                if ($isNew || (array_key_exists('last_name', $data) && empty($data['last_name']) && empty($existing?->last_name))) {
                    $data['last_name'] = $data['last_name'] ?? $borrower->last_name;
                }
                if ($isNew || (array_key_exists('phone_number', $data) && empty($data['phone_number']) && empty($existing?->phone_number))) {
                    $data['phone_number'] = $data['phone_number'] ?? $borrower->phone_number;
                }
                if ($isNew || (array_key_exists('date_of_birth', $data) && empty($data['date_of_birth']) && empty($existing?->date_of_birth))) {
                    $data['date_of_birth'] = $data['date_of_birth'] ?? $borrower->date_of_birth;
                }
                if ($isNew || (array_key_exists('ssn', $data) && empty($data['ssn']) && empty($existing?->ssn))) {
                    $data['ssn'] = $data['ssn'] ?? $borrower->last_4_ssn;
                }
                if ($isNew || (array_key_exists('street_address', $data) && empty($data['street_address']) && empty($existing?->street_address))) {
                    $data['street_address'] = $data['street_address'] ?? $borrower->home_address;
                }
                if ($isNew || (array_key_exists('country', $data) && empty($data['country']) && empty($existing?->country))) {
                    $data['country'] = $data['country'] ?? $borrower->country;
                }
                if ($isNew || (array_key_exists('city', $data) && empty($data['city']) && empty($existing?->city))) {
                    $data['city'] = $data['city'] ?? $borrower->city;
                }
                if ($isNew || (array_key_exists('state', $data) && empty($data['state']) && empty($existing?->state))) {
                    $data['state'] = $data['state'] ?? $borrower->state;
                }
                if ($isNew || (array_key_exists('zip_code', $data) && empty($data['zip_code']) && empty($existing?->zip_code))) {
                    $data['zip_code'] = $data['zip_code'] ?? $borrower->postal_code;
                }
                
                // Business Info
                if ($isNew || (array_key_exists('business_name', $data) && empty($data['business_name']) && empty($existing?->business_name))) {
                    $data['business_name'] = $data['business_name'] ?? $borrower->business_name;
                }
                if ($isNew || (array_key_exists('business_email', $data) && empty($data['business_email']) && empty($existing?->business_email))) {
                    $data['business_email'] = $data['business_email'] ?? ($borrower->business_email ?? $borrower->email);
                }
                if ($isNew || (array_key_exists('business_phone_no', $data) && empty($data['business_phone_no']) && empty($existing?->business_phone_no))) {
                    $data['business_phone_no'] = $data['business_phone_no'] ?? ($borrower->business_phone ?? $borrower->phone_number);
                }
                if ($isNew || (array_key_exists('business_country', $data) && empty($data['business_country']) && empty($existing?->business_country))) {
                    $data['business_country'] = $data['business_country'] ?? ($borrower->business_country ?? $borrower->country);
                }
                if ($isNew || (array_key_exists('business_country_code', $data) && empty($data['business_country_code']) && empty($existing?->business_country_code))) {
                    $data['business_country_code'] = $data['business_country_code'] ?? ($borrower->business_country_code ?? $borrower->country_code);
                }
                if ($isNew || (array_key_exists('business_address', $data) && empty($data['business_address']) && empty($existing?->business_address))) {
                    $data['business_address'] = $data['business_address'] ?? $borrower->business_address;
                }
                if ($isNew || (array_key_exists('business_city', $data) && empty($data['business_city']) && empty($existing?->business_city))) {
                    $data['business_city'] = $data['business_city'] ?? $borrower->business_city;
                }
                if ($isNew || (array_key_exists('business_state', $data) && empty($data['business_state']) && empty($existing?->business_state))) {
                    $data['business_state'] = $data['business_state'] ?? $borrower->business_state;
                }
                if ($isNew || (array_key_exists('business_postal_code', $data) && empty($data['business_postal_code']) && empty($existing?->business_postal_code))) {
                    $data['business_postal_code'] = $data['business_postal_code'] ?? $borrower->business_postal_code;
                }
                if ($isNew || (array_key_exists('industry_type', $data) && empty($data['industry_type']) && empty($existing?->industry_type))) {
                    $data['industry_type'] = $data['industry_type'] ?? $borrower->business_industry_type;
                }
                if ($isNew || (array_key_exists('entity_type', $data) && empty($data['entity_type']) && empty($existing?->entity_type))) {
                    $data['entity_type'] = $data['entity_type'] ?? $borrower->business_entity_type;
                }
                if ($isNew || (array_key_exists('years_in_business', $data) && empty($data['years_in_business']) && empty($existing?->years_in_business))) {
                    $data['years_in_business'] = $data['years_in_business'] ?? $borrower->years_in_business;
                }
                if ($isNew || (array_key_exists('number_of_employees', $data) && empty($data['number_of_employees']) && empty($existing?->number_of_employees))) {
                    $data['number_of_employees'] = $data['number_of_employees'] ?? $borrower->number_of_employees;
                }
                
                // Financial Info
                if ($isNew || (array_key_exists('monthly_revenue', $data) && empty($data['monthly_revenue']) && empty($existing?->monthly_revenue))) {
                    $data['monthly_revenue'] = $data['monthly_revenue'] ?? $borrower->monthly_revenue;
                }
                if ($isNew || (array_key_exists('annual_revenue', $data) && empty($data['annual_revenue']) && empty($existing?->annual_revenue))) {
                    $data['annual_revenue'] = $data['annual_revenue'] ?? $borrower->annual_revenue;
                }
                if ($isNew || (array_key_exists('business_checking_start_date', $data) && empty($data['business_checking_start_date']) && empty($existing?->business_checking_start_date))) {
                    $data['business_checking_start_date'] = $data['business_checking_start_date'] ?? $borrower->business_start_date;
                }
                if ($isNew || (array_key_exists('self_reported_personal_credit_score', $data) && empty($data['self_reported_personal_credit_score']) && empty($existing?->self_reported_personal_credit_score))) {
                    $data['self_reported_personal_credit_score'] = $data['self_reported_personal_credit_score'] ?? $borrower->fico_score;
                }
                if ($isNew || (array_key_exists('self_reported_business_credit_score', $data) && empty($data['self_reported_business_credit_score']) && empty($existing?->self_reported_business_credit_score))) {
                    $data['self_reported_business_credit_score'] = $data['self_reported_business_credit_score'] ?? $borrower->paydex_score;
                }
                if ($isNew || (array_key_exists('has_business_debt', $data) && !isset($data['has_business_debt']) && !isset($existing?->has_business_debt))) {
                    $data['has_business_debt'] = $data['has_business_debt'] ?? ($borrower->current_business_debt ? true : false);
                }
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
