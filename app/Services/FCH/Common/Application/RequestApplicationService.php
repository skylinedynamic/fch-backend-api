<?php

namespace App\Services\FCH\Common\Application;

use App\Helpers\ApiResponse;
use App\Http\Resources\Borrower\MyApplication\FundingApplicationResource;
use App\Http\Resources\Borrower\MyApplication\PreQualificationApplicationResource;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource as ApplicationFundingApplicationResource;
use App\Http\Resources\FCH\Common\Application\PreQualificationRequestResource;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Commission\Commission;
use App\Models\Common\AppConfiguration;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Application\SignatureCertificateService;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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

        $brandingData = self::getBrandingData($application);
        $pdf = Pdf::loadView('pdf.funding-application', array_merge(['application' => $application], $brandingData));
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

        $brandingData = self::getBrandingData($application);
        $templateData = SignatureCertificateService::getTemplateData($application, $brandingData, $signatureData);
        $pdf = Pdf::loadView('pdf.signature-certificate', array_merge($brandingData, $templateData));
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

        // Generate standard PDFs (the ZIP archive itself will handle the password protection)
        $appContent = $this->generateApplicationPdfContent($application, null);
        $sigContent = $this->generateSignaturePdfContent($application, $signatureData, null);

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

    private function generateApplicationPdfContent($application, $password = null)
    {
        $brandingData = self::getBrandingData($application);
        $pdf = Pdf::loadView('pdf.funding-application', array_merge(['application' => $application], $brandingData));
        if (!empty($password)) {
            $pdf->setEncryption($password);
        }
        return $pdf->output();
    }

    private function generateSignaturePdfContent($application, $signatureData, $password = null)
    {
        $brandingData = self::getBrandingData($application);
        $templateData = SignatureCertificateService::getTemplateData($application, $brandingData, $signatureData);
        $pdf = Pdf::loadView('pdf.signature-certificate', array_merge($brandingData, $templateData));
        if (!empty($password)) {
            $pdf->setEncryption($password);
        }
        return $pdf->output();
    }

    /**
     * Get branding data (colors, company details, logo base64) for PDFs
     */
    public static function getBrandingData($application)
    {
        if ($application && ($application->relationLoaded('borrower') === false || !$application->borrower || !$application->borrower->first_name)) {
            $application->load('borrower');
        }

        $branding = self::getCompanyBrandingInfo($application);
        $borrowerInfo = self::getBorrowerBusinessInfo($application);
        $sigInfo = self::getApplicationSignatureInfo($application, $branding['primaryColor'], $borrowerInfo['firstName'], $borrowerInfo['lastName']);

        return array_merge($branding, $borrowerInfo, $sigInfo);
    }

    private static function getCompanyBrandingInfo($application)
    {
        $appConfigService = new AppConfigurationService();
        $module = AppConfiguration::MODULE_BRANDING;
        $partnerId = $application->partner_id;

        // Try application partner branding
        $branding = $appConfigService->getByModule($module, $partnerId);

        $hostPartner = Partner::where('is_host', true)->first();
        
        // Fallback to host partner branding
        if (empty($branding)) {
            $branding = $appConfigService->getByModule($module, $hostPartner ? $hostPartner->id : null);
        }

        $address = $appConfigService->get('address', null, null, $partnerId) ?? ($hostPartner ? $appConfigService->get('address', null, null, $hostPartner->id) : null);
        $phone = $appConfigService->get('phone_number', null, null, $partnerId) ?? ($hostPartner ? $appConfigService->get('phone_number', null, null, $hostPartner->id) : null);
        $email = $appConfigService->get('email', null, null, $partnerId) ?? ($hostPartner ? $appConfigService->get('email', null, null, $hostPartner->id) : null);

        $contactParts = array_filter([$phone, $email]);
        
        $logoKey = $branding['primary_logo'] ?? $branding['secondary_logo'] ?? $branding['header_logo'] ?? null;
        $logoBase64 = '';
        $paths = $logoKey && $logoKey !== 'null' ? [
            storage_path('app/public/' . ltrim((string) $logoKey, '/')),
            public_path('storage/' . ltrim((string) $logoKey, '/')),
            public_path(ltrim((string) $logoKey, '/'))
        ] : [];
        
        $paths = array_merge($paths, [public_path('logo-mail.png'), public_path('logo.png')]);

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $logoBase64 = 'data:image/' . pathinfo($path, PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($path));
                break;
            }
        }

        return [
            'primaryColor' => $branding['primary_color'] ?? '#2563a2',
            'secondaryColor' => $branding['secondary_color'] ?? '#D4AF37',
            'companyName' => $branding['site_name'] ?? config('app.name'),
            'companyAddress' => $address ?? '',
            'companyCityState' => '',
            'companyContact' => implode('  |  ', $contactParts),
            'companyWebsite' => '',
            'logoBase64' => $logoBase64,
        ];
    }

    private static function getBorrowerBusinessInfo($application)
    {
        $borrower = $application->borrower ?? null;

        $businessName = ($borrower ? $borrower->business_name : null) ?: ($application->business_name ?: 'N/A');
        $businessPhone = ($borrower ? $borrower->business_phone : null) ?: ($application->business_phone ?: 'N/A');
        $businessAddress = ($borrower ? $borrower->business_address : null) ?: ($application->street_address ?: 'N/A');
        $businessCity = ($borrower ? $borrower->business_city : null) ?: ($application->city ?: 'N/A');
        $personalCreditScore = ($borrower ? $borrower->personal_credit_score : null) ?: ($application->self_reported_personal_credit_score ?: 'N/A');
        $businessEin = ($borrower ? $borrower->business_ein : null) ?: ($application->federal_tax_id_ein ?: 'N/A');
        $businessEmail = ($borrower ? $borrower->business_email : null) ?: ($application->business_email ?: 'N/A');
        $businessCountry = ($borrower ? $borrower->business_country : null) ?: ($application->country ?: 'N/A');
        $businessState = ($borrower ? $borrower->business_state : null) ?: ($application->state ?: 'N/A');
        $businessZipCode = ($borrower ? $borrower->business_postal_code : null) ?: ($application->zip_code ?: 'N/A');
        
        $rawStartDate = ($borrower ? $borrower->business_start_date : null) ?: ($application->business_start_date ?? null);
        $businessStartDate = !empty($rawStartDate) ? Carbon::parse($rawStartDate)->format('M j, Y') : 'N/A';
        $timeInBusinessCount = calculateTimeInBusiness($rawStartDate);
        
        $businessCreditScore = $application->self_reported_business_credit_score ?: 'N/A';
        $businessIndustryType = ($borrower ? $borrower->business_industry_type : null) ?: ($application->industry_type ?: 'N/A');

        $firstName = ($borrower ? $borrower->first_name : null) ?: ($application->first_name ?: 'N/A');
        $lastName = ($borrower ? $borrower->last_name : null) ?: ($application->last_name ?: 'N/A');
        $email = ($borrower ? $borrower->email : null) ?: ($application->email ?: 'N/A');
        $phoneNumber = ($borrower ? $borrower->phone_number : null) ?: ($application->phone_number ?: 'N/A');
        $homeAddress = ($borrower ? $borrower->home_address : null) ?: ($application->home_street_address ?: 'N/A');
        $homeState = ($borrower ? $borrower->state : null) ?: ($application->home_state ?: 'N/A');
        $homeCity = ($borrower ? $borrower->city : null) ?: ($application->home_city ?: 'N/A');
        $homeZipCode = ($borrower ? $borrower->postal_code : null) ?: ($application->home_zip_code ?: 'N/A');
        $homeCountry = ($borrower ? $borrower->country : null) ?: ($application->home_country ?: 'N/A');
        $homePhone = ($borrower ? $borrower->home_phone : null) ?: ($application->home_phone ?: 'N/A');
        
        $rawDob = ($borrower ? $borrower->date_of_birth : null) ?: ($application->date_of_birth ?? null);
        $dateOfBirth = !empty($rawDob) ? Carbon::parse($rawDob)->format('M j, Y') : 'N/A';
        
        $last4SSN = ($borrower ? $borrower->last_4_ssn : null) ?: (!empty($application->ssn) ? substr($application->ssn, -4) : 'N/A');

        $fundingUses = $application->uses ?: (optional($application->fundingSequence)->uses ?: 'N/A');
        if (is_array($fundingUses)) {
            $fundingUses = implode(', ', $fundingUses);
        }

        return [
            'businessName' => $businessName,
            'businessPhone' => $businessPhone,
            'businessAddress' => $businessAddress,
            'businessCity' => $businessCity,
            'personalCreditScore' => $personalCreditScore,
            'businessEin' => $businessEin,
            'businessEmail' => $businessEmail,
            'businessCountry' => $businessCountry,
            'businessState' => $businessState,
            'businessZipCode' => $businessZipCode,
            'businessStartDate' => $businessStartDate,
            'businessCreditScore' => $businessCreditScore,
            'businessIndustryType' => $businessIndustryType,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'phoneNumber' => $phoneNumber,
            'homeAddress' => $homeAddress,
            'homeState' => $homeState,
            'homeCity' => $homeCity,
            'homeZipCode' => $homeZipCode,
            'homeCountry' => $homeCountry,
            'homePhone' => $homePhone,
            'dateOfBirth' => $dateOfBirth,
            'last4SSN' => $last4SSN,
            'fundingUses' => $fundingUses,
            'timeInBusinessCount' => $timeInBusinessCount,
        ];
    }

    private static function getApplicationSignatureInfo($application, $primaryColor, $firstName, $lastName)
    {
        $tz = request()->header('X-Timezone') ?? config('app.timezone');
        
        $rawSigData = $application->signature_data ?? [];
        $sigData = is_string($rawSigData) ? json_decode($rawSigData, true) : $rawSigData;
        if(!is_array($sigData)) $sigData = [];
        
        $appId = $application->application_number ?? ('FUND-'.str_pad($application->id, 6, '0', STR_PAD_LEFT));
        
        $borrowerName = trim(($firstName !== 'N/A' ? $firstName : '') . ' ' . ($lastName !== 'N/A' ? $lastName : ''));
        if (empty($borrowerName)) {
            $borrowerName = $application->full_name ?? '';
        }
        
        $status = ucfirst(str_replace('_', ' ', $application->status ?? 'Pending'));
        
        $ip = $sigData['ip_address'] ?? '127.0.0.1';
        $browser = $sigData['browser_name'] ?? 'Chrome';
        $os = $sigData['operating_system'] ?? 'Windows';
        $device = $sigData['device_type'] ?? 'Desktop';
        
        $sigName = $sigData['signature_name'] ?? $borrowerName;
        if(empty($sigName)) $sigName = 'N/A';
        
        $sigTime = !empty($sigData['signed_at']) ? Carbon::parse($sigData['signed_at'])->timezone($tz) : now()->timezone($tz);
        $sigDateStr = $sigTime->format('M j, Y');
        $sigTimeStr = $sigTime->format('h:i:s A');
        $timezone = $sigTime->format('e') . ' (UTC' . $sigTime->format('P') . ')';
        
        $currentTime = now()->timezone($tz);
        $timezoneAbbr = $currentTime->format('T');
        
        $termsVer = $sigData['terms_version'] ?? '1.0';
        $privacyVer = $sigData['privacy_version'] ?? '1.0';
        $creditVer = $sigData['credit_auth_version'] ?? '1.0';
        $sha = $sigData['sha256'] ?? 'N/A';
        
        $icon_white = function(string $path, string $viewBox = '0 0 512 512') {
            return 'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '"><path fill="#ffffff" d="' . $path . '"/></svg>'
            );
        };
        $icon_primary = function(string $path, string $viewBox = '0 0 512 512') use ($primaryColor) {
            return 'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '"><path fill="' . $primaryColor . '" d="' . $path . '"/></svg>'
            );
        };

        $p_building = 'M436 480h-20V24c0-13.3-10.7-24-24-24H56C42.7 0 32 10.7 32 24v456H12c-6.6 0-12 5.4-12 12v20h448v-20c0-6.6-5.4-12-12-12zM128 76c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12V76zm0 96c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm52 148h-40c-6.6 0-12-5.4-12-12v-40c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12zm76 160h-64v-84c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v84zm64-172c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40zm0-96c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40zm0-96c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12V76c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40z';
        $p_user = 'M256 288c79.5 0 144-64.5 144-144S335.5 0 256 0 112 64.5 112 144s64.5 144 144 144zm128 32h-55.1c-22.2 10.2-46.9 16-72.9 16s-50.6-5.8-72.9-16H128C57.3 320 0 377.3 0 448v16c0 26.5 21.5 48 48 48h416c26.5 0 48-21.5 48-48v-16c0-70.7-57.3-128-128-128z';
        $p_dollar = 'M272 128c0-14.2-3.2-27.8-8.9-40h17.7c8.8 0 16-7.2 16-16V16c0-8.8-7.2-16-16-16h-48.1c-43 0-80.3 28-93.7 66.7C126.5 62.2 113.8 56 99.9 56c-41.2 0-67.2 44.9-46.4 80.3L120 248c8.1 13.6 22.6 22.2 38.5 23.7V320H128c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h30.5v32.4c-17.4 7.3-28.5 26.5-24.1 47.4 3.6 17 18.3 30.7 35.7 32.1C191.7 497.4 208 481.9 208 464v-80h30.5c26.3 0 47.5-21.3 47.5-47.5V320h.5c44.2 0 80-35.8 80-80 0-61.9-50.1-112-94-112zm-48 208h-32v-48h32v48z';
        $p_shield = 'M466.5 83.7l-192-80a48.15 48.15 0 0 0-36.9 0l-192 80C27.7 91.1 16 108.6 16 128c0 198.5 114.5 335.7 221.5 380.3 11.8 4.9 25.1 4.9 36.9 0C381.5 463.7 496 326.5 496 128c0-19.4-11.7-36.9-29.5-44.3zm-47.2 114.2l-184 184c-6.2 6.2-16.4 6.2-22.6 0l-104-104c-6.2-6.2-6.2-16.4 0-22.6l22.6-22.6c6.2-6.2 16.4-6.2 22.6 0l70.1 70.1 150.1-150.1c6.2-6.2 16.4-6.2 22.6 0l22.6 22.6c6.3 6.2 6.3 16.4 0 22.6z';
        $p_file = 'M224 136V0H24C10.7 0 0 10.7 0 24v464c0 13.3 10.7 24 24 24h336c13.3 0 24-10.7 24-24V160H248c-13.2 0-24-10.8-24-24zm64 236c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12v8zm0-64c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12v8zm0-72v8c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12zm96-114.1v6.1H256V0h6.1c6.4 0 12.5 2.5 17 7l97.9 98c4.5 4.5 7 10.6 7 16.9z';
        $p_cal = 'M0 464c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48V192H0v272zm320-196c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm-128-128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm-128-128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-40zM400 64h-48V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H160V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H48C21.5 64 0 85.5 0 112v48h448v-48c0-26.5-21.5-48-48-48z';
        $fa_doc_white    = $icon_white($p_file, '0 0 384 512');
        $fa_cal_white    = $icon_white($p_cal);
        $fa_doc          = $icon_primary($p_building);      
        $fa_users        = $icon_primary($p_user, '0 0 448 512');  
        $fa_dollar       = $icon_primary($p_dollar);        
        $fa_shield       = $icon_primary($p_shield);        

        return [
            'appId' => $appId,
            'borrowerName' => $borrowerName,
            'status' => $status,
            'ip' => $ip,
            'browser' => $browser,
            'os' => $os,
            'device' => $device,
            'sigName' => $sigName,
            'sigDateStr' => $sigDateStr,
            'sigTimeStr' => $sigTimeStr,
            'timezone' => $timezone,
            'termsVer' => $termsVer,
            'privacyVer' => $privacyVer,
            'creditVer' => $creditVer,
            'sha' => $sha,
            'currentTime' => $currentTime,
            'timezoneAbbr' => $timezoneAbbr,
            'fa_doc_white' => $fa_doc_white,
            'fa_cal_white' => $fa_cal_white,
            'fa_doc' => $fa_doc,
            'fa_users' => $fa_users,
            'fa_dollar' => $fa_dollar,
            'fa_shield' => $fa_shield,
        ];
    }
}
