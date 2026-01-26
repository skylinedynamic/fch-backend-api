<?php

namespace App\Services\Borrower\FundApplication;

use App\Models\Borrower\Borrower;
use App\Models\User\User;

use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\Borrower\BorrowerDocument;
use Illuminate\Support\Facades\Storage;


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
        $fundingInfo = FundingApplication::where('borrower_id', auth()->user()->entity_id)
                ->with('borrower','coborrowers')
                ->where('status', FundingApplication::ON_HOLD)
                ->latest()
                ->first();

        if($fundingInfo){
            return $fundingInfo;
        }else{
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

            $fundingApplication = FundingApplication::updateOrCreate(
                ['id' => $data['id']],
                $data
            );


            if(isset($data['has_co_borrower']) && $data['has_co_borrower'] && count($data['co_borrowers']) > 0){
                foreach ($data['co_borrowers'] as $coBorrower) {
                    $coBorrower['funding_application_id'] = $fundingApplication->id;

                    FundingApplicationCoBorrower::updateOrCreate(['id' => $coBorrower['id'] ?? null], [
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
                }
            }

            if($fundingApplication){
                $this->createFundingApplicationDocumentDir($fundingApplication->id);
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

        $sourceDir = "borrowers/{$borrowerId}";
        $destinationDir = "funding-application/{$borrowerId}/FA-{$fundingApplicationId}";

        if (!Storage::exists($destinationDir)) {
            Storage::makeDirectory($destinationDir);
        }

        $files = Storage::allFiles($sourceDir);

        foreach ($files as $file) {
            $relativePath = str_replace($sourceDir . '/', '', $file);
            $destinationPath = $destinationDir . '/' . $relativePath;

            $destinationSubDir = dirname($destinationPath);
            if (!Storage::exists($destinationSubDir)) {
                Storage::makeDirectory($destinationSubDir);
            }

            Storage::copy($file, $destinationPath);

            $originalDocument = BorrowerDocument::where('borrower_id', $borrowerId)
                ->where('file_path', 'LIKE', '%' . basename($file))
                ->first();

            FundingApplicationDocument::create([
                'funding_application_id' => $fundingApplicationId,
                'borrower_id' => $borrowerId,
                'borrower_doc_type_id' => $originalDocument->document_type_id ?? null,
                'name' => $originalDocument->file_name ?? basename($file),
                'path' => $destinationPath,
                'disk' => 'public',
                'original_name' => $originalDocument->file_name ?? basename($file),
                'extension' => pathinfo($file, PATHINFO_EXTENSION),
                'mime' => Storage::mimeType($file),
                'size' => Storage::size($file),
            ]);
        }

        return true;
    }
}
