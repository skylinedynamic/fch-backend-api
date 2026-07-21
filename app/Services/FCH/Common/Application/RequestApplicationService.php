<?php

namespace App\Services\FCH\Common\Application;

use App\Helpers\ApiResponse;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\FundingRequest\FundingRequest;
use App\Http\Resources\Borrower\MyApplication\PreQualificationApplicationResource;
use App\Http\Resources\Borrower\MyApplication\FundingApplicationResource;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource as ApplicationFundingApplicationResource;
use App\Http\Resources\FCH\Common\Application\PreQualificationRequestResource;
use App\Models\User\User;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Commission\Commission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class RequestApplicationService
{
    use PaginatesApi;

    /**
     * Get the authenticated user's partner ID
     *
     * @return int
     */
    private function getAuthenticatedPartnerId(): int
    {
        return Auth::user()->partner_id;
    }

    /**
     * Get PreQualification Applications List
     */
    public function getPreQualificationList(array $filters)
    {
        $partnerId = $this->getAuthenticatedPartnerId();
        
        $query = PreQualificationRequest::query()
        ->where('status', '!=', PreQualificationRequest::ON_HOLD)
        ->when(Auth::user()->user_type == User::PARTNER, function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        });

        if(isset($filters['status']) && !empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if(isset($filters['start_date']) && !empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if(isset($filters['end_date']) && !empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if(isset($filters['funding_type']) && !empty($filters['funding_type'])) {
            $query->where('purpose_of_funding', $filters['funding_type']);
        }

        // Apply Search
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->search($search);
        }

        // Apply Sorting & Advanced Filters using QueryFilter if available, or manual simple sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $advancedFilters = $filters['filters'] ?? [];


        if($sortField == 'funding_type') {
            $sortField = 'purpose_of_funding';
        }elseif($sortField == 'requested_amount') {
            $sortField = 'funding_requested_amount';
        }elseif($sortField == 'date') {
            $sortField = 'created_at';
        }elseif($sortField == 'full_name') {
            $sortField = 'first_name';
        }


        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $advancedFilters,
             [

                'aliases' => [
                    'purpose_of_funding' => 'funding_type',
                    'funding_requested_amount' => 'requested_amount',
                    'created_at' => 'date',
                    'first_name' => 'full_name',
                ],
            ]
        );

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator, 
            PreQualificationApplicationResource::class,
            __('application.pre_qualification_retrieved')
        );
    }

    /**
     * Get Funding Applications List
     */
    public function getFundingApplicationList(array $filters)
        {
            $authUser = Auth::user();
            
            $query = FundingApplication::query()
                ->where('status', '!=', FundingApplication::ON_HOLD)
                ->when($authUser->user_type == User::PARTNER, function($query) use($authUser){
                    $query->where('partner_id', $authUser->partner_id);
                });

            // Apply Search
            if (!empty($filters['search'])) {
                $search = trim((string) $filters['search']);
                $query->search($search);
            }

            $sortField = $filters['sort_field'] ?? 'id';
            $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $advancedFilters = $filters['filters'] ?? [];

            $query = QueryFilter::apply(
                $query,
                $sortField,
                $sortOrder,
                $advancedFilters,
                []
            );

            $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
            $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

            $paginator = $this->paginateBuilderWith($query, $perPage, $page);

            return $this->respondWithPaginationResource(
                $paginator,
                FundingApplicationResource::class,
                __('application.funding_application_retrieved')
            );
        }
    /**
     * Get Pre-Qualification Application By ID
     */
    public function getPreQualificationById($id)
    {
        $partnerId = $this->getAuthenticatedPartnerId();
        
        $application = PreQualificationRequest::with('borrower')
           ->when(Auth::user()->user_type == User::PARTNER, function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        })
        ->where('id',$id)->first();

        if($application == null){
            return ApiResponse::error(__('application.pre_qualification_not_found'));
        }
        return ApiResponse::success(new PreQualificationRequestResource($application));
    }

    /**
     * Get Funding Application By ID
     */
    public function getFundingApplicationById($id)
    {
        $authUser = Auth::user();

        $application = FundingApplication::with('commission','borrower:id,partner_id','coborrowers','fundingDocuments','fundingDocuments.docType')
           ->when($authUser->user_type == User::PARTNER, function($query) use($authUser){
            $query->where('partner_id', $authUser->partner_id);
           })
        ->where('id',$id)->first();

        if($application == null){
            return ApiResponse::error(__('application.funding_application_not_found'));
        }
        //return ApiResponse::success($application);
        return ApiResponse::success(new ApplicationFundingApplicationResource($application));
    }

    /**
     * Update Pre-qualification Application Status
     */
    public function preQualificationStatusUpdate(array $data)
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $application = PreQualificationRequest::with('borrower')
        ->where('id',$data['id'])->first();

        if($application == null){
            return ApiResponse::error(__('application.pre_qualification_not_found'));
        }

        $application->status = $data['status'];
        if (isset($data['status_note'])) {
            $application->status_note = $data['status_note'];
        }
        $application->save();

        return ApiResponse::success(new PreQualificationRequestResource($application), __('application.status_updated'));
    }

    /**
     * Update Funding Application Status
     */
    public function fundingApplicationStatusUpdate(array $data)
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $application = FundingApplication::with('borrower')
        ->where('id',$data['funding_application_id'])->first();

        if($application == null){
            return ApiResponse::error(__('application.funding_application_not_found'));
        }

        if($data['status'] == FundingApplication::CLOSED_COMPLETED){
            $application->total_funded_amount = $data['funded_amount'];
            $application->funded_date = Carbon::now();
        }

        $application->status = $data['status'];
        if (isset($data['status_note'])) {
            $application->status_note = $data['status_note'];
        }
        $application->save();

        if ($application->status == FundingApplication::CLOSED_COMPLETED && $application->total_funded_amount > 0) {
            $commission = Commission::where('funding_application_id', $application->id)->first();
            if ($commission) {
                // Ensure we use the correct base amount
                $baseAmount = $application->total_funded_amount;
                $totalCommissionAmount = ($baseAmount * $commission->total_commission) / 100;
                
                $adminCommissionAmount = $totalCommissionAmount;
                $partnerCommissionAmount = 0;

                if ($commission->partner_commission > 0) {
                    $partnerCommissionAmount = ($totalCommissionAmount * $commission->partner_commission) / 100;
                    $adminCommissionAmount = $totalCommissionAmount - $partnerCommissionAmount;
                }

                $commission->update([
                    'total_commission_amount' => $totalCommissionAmount,
                    'admin_commission_amount' => $adminCommissionAmount,
                    'partner_commission_amount' => $partnerCommissionAmount,
                    'commission_status' => Commission::STATUS_PROCESSING,
                    'payment_status' => Commission::PAYMENT_STATUS_UNPAID,
                ]);
            }
        }

        return ApiResponse::success(new ApplicationFundingApplicationResource($application), __('application.status_updated'));
    }
    /**
     * Get Pre-Qualification Card Summary
     */
    public function getPreQualificationCardSummary()
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $baseQuery = PreQualificationRequest::where('status', '!=', PreQualificationRequest::ON_HOLD)
            ->when(Auth::user()->user_type == User::PARTNER, function ($q) use($partnerId) {
                $q->where('partner_id', $partnerId);
            });

        return ApiResponse::success([
            'total_applications' => (clone $baseQuery)->count(),
            'approved' => (clone $baseQuery)->where('status', PreQualificationRequest::APPROVED)->count(),
            'pending' => (clone $baseQuery)->where('status', PreQualificationRequest::PENDING)->count(),
            'approved_amount' => (clone $baseQuery)->where('status', PreQualificationRequest::APPROVED)->sum('funding_requested_amount'),
        ]);
    }

    /**
     * Get Funding Application Card Summary
     */
    public function getFundingApplicationCardSummary()
    {
        $authUser = Auth::user();

        $baseQuery = FundingApplication::where('status', '!=', FundingApplication::ON_HOLD)
            ->when($authUser->user_type == User::PARTNER, function($query) use($authUser){
                $query->where('partner_id', $authUser->partner_id);
            });

        return ApiResponse::success([
            'total_applications' => (clone $baseQuery)->count(),
            'approved' => (clone $baseQuery)->where('status', FundingApplication::CLOSED_COMPLETED)->count(),
            'pending' => (clone $baseQuery)->where('status', FundingApplication::PENDING)->count(),
            'approved_amount' => currencyFormat((clone $baseQuery)->where('status', FundingApplication::CLOSED_COMPLETED)->sum('requested_amount')),
        ]);
    }

    public function downloadApplicationPdf($id, $password = null)
    {
        $authUser = Auth::user();

        $application = FundingApplication::with('commission', 'borrower:id,partner_id', 'coborrowers', 'fundingDocuments', 'fundingDocuments.docType')
           ->when($authUser->user_type == User::PARTNER, function($query) use($authUser){
                $query->where('partner_id', $authUser->partner_id);
           })
        ->where('id', $id)->first();

        if ($application == null) {
            return ApiResponse::error(__('application.funding_application_not_found'));
        }

        $pdf = Pdf::loadView('pdf.funding-application', compact('application'));
        if (!empty($password)) {
            $pdf->setEncryption($password);
        }
        
        $filename = 'Funding_Application_' . ($application->application_number ?? $application->id) . '.pdf';
        
        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function downloadSignaturePdf($id, $password = null)
    {
        $authUser = Auth::user();

        $application = FundingApplication::when($authUser->user_type == User::PARTNER, function($query) use($authUser){
                $query->where('partner_id', $authUser->partner_id);
           })
        ->where('id', $id)->first();

        if ($application == null) {
            return ApiResponse::error(__('application.funding_application_not_found'));
        }

        $signatureData = $application->signature_data;
        if (empty($signatureData)) {
            return ApiResponse::error('Signature data not found.');
        }

        $pdf = Pdf::loadView('pdf.signature-certificate', compact('application', 'signatureData'));
        if (!empty($password)) {
            $pdf->setEncryption($password);
        }
        
        $filename = 'Signature_Certificate_' . ($application->application_number ?? $application->id) . '.pdf';

        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function downloadZip($id, $password = null)
    {
        $authUser = Auth::user();

        $application = FundingApplication::with('commission', 'borrower:id,partner_id', 'coborrowers', 'fundingDocuments', 'fundingDocuments.docType')
            ->when($authUser->user_type == User::PARTNER, function ($query) use ($authUser) {
                $query->where('partner_id', $authUser->partner_id);
            })
            ->where('id', $id)->first();

        if ($application == null) {
            return ApiResponse::error(__('application.funding_application_not_found'));
        }

        $signatureData = $application->signature_data;
        if (empty($signatureData)) {
            return ApiResponse::error('Signature data not found. Cannot generate ZIP.');
        }

        // Generate plain PDFs (no individual PDF encryption)
        $appPdf = Pdf::loadView('pdf.funding-application', compact('application'));
        $appContent = $appPdf->output();

        $sigPdf = Pdf::loadView('pdf.signature-certificate', compact('application', 'signatureData'));
        $sigContent = $sigPdf->output();

        $appNumber = $application->application_number ?? $application->id;
        $appFilename = 'Funding_Application_' . $appNumber . '.pdf';
        $sigFilename  = 'Signature_Certificate_' . $appNumber . '.pdf';
        $zipFilename  = 'Funding_Documents_' . $appNumber . '.zip';

        // Write PDFs to temp files (ZipArchive requires file paths for encryption)
        $appTempFile = tempnam(sys_get_temp_dir(), 'app_pdf_') . '.pdf';
        $sigTempFile = tempnam(sys_get_temp_dir(), 'sig_pdf_') . '.pdf';
        file_put_contents($appTempFile, $appContent);
        file_put_contents($sigTempFile, $sigContent);

        $zipTempFile = tempnam(sys_get_temp_dir(), 'fch_zip_') . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipTempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($appTempFile);
            @unlink($sigTempFile);
            return ApiResponse::error('Could not create ZIP archive.');
        }

        if (!empty($password)) {
            // Password protected — encrypt with ZipCrypto (natively supported by Windows/macOS/7-Zip)
            $zip->setPassword($password);
            $zip->addFile($appTempFile, $appFilename);
            $zip->setEncryptionName($appFilename, \ZipArchive::EM_TRAD_PKWARE);
            $zip->addFile($sigTempFile, $sigFilename);
            $zip->setEncryptionName($sigFilename, \ZipArchive::EM_TRAD_PKWARE);
        } else {
            // Without password — plain ZIP, anyone can open
            $zip->addFile($appTempFile, $appFilename);
            $zip->addFile($sigTempFile, $sigFilename);
        }

        $zip->close();

        // Clean up temp PDF files
        @unlink($appTempFile);
        @unlink($sigTempFile);

        return response()->download($zipTempFile, $zipFilename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
