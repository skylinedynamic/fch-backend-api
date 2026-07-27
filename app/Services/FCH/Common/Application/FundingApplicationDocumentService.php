<?php

namespace App\Services\FCH\Common\Application;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingApplicationCertificate;
use App\Models\Borrower\DownloadHistory;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Models\ActivityLog\ActivityLog;
use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Models\Common\AppConfiguration;
use Illuminate\Support\Carbon;
use App\Helpers\ApiResponse;

class FundingApplicationDocumentService
{
    protected $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    /**
     * Generate and lock the final PDF documents for a funding application.
     *
     * @param FundingApplication $application
     * @param array $signatureData
     * @return void
     */
    public function generateAndLockFinalDocuments(FundingApplication $application, array &$signatureData)
    {
        try {
            $brandingData = self::getBrandingData($application);
            
            // 1. Generate Application PDF
            $appPdf = Pdf::loadView('pdf.funding-application', array_merge(['application' => $application], $brandingData));
            $appPdfBinary = $appPdf->output();
            
            // 2. Calculate SHA-256
            $appSha256 = hash('sha256', $appPdfBinary);
            
            // 3. Save Application PDF
            $appPdfPath = 'funding-applications/' . $application->id . '/application-' . $application->application_number . '.pdf';
            Storage::disk('public')->put($appPdfPath, $appPdfBinary);

            $user = Auth::user();
            $userName = $user ? trim($user->first_name . ' ' . $user->last_name) : 'System';
            if (!$userName) $userName = 'System';
            $userType = $user->user_type ?? 'system';

            // 4. Set Certificate ID
            $certId = (string) Str::uuid();

            // Log document generation history
            DownloadHistory::create([
                'user_id' => $user ? $user->id : null,
                'user_name' => $userName,
                'user_type' => $userType,
                'funding_application_id' => $application->id,
                'application_number' => $application->application_number,
                'application_version' => $application->application_version ?? '1.0',
                'certificate_id' => $certId,
                'file_type' => 'application_pdf',
                'action' => 'generated',
                'sha256_hash' => $appSha256,
                'ip_address' => request()->ip(),
                'downloaded_at' => now(),
            ]);

            $this->activityLogService->logModelEvent(
                $application,
                ActivityLog::TYPE_ACTIVITY,
                ['visible_to_borrower' => '1'],
                'Funding Application Generated',
                "Funding Application PDF generated and locked. SHA-256: {$appSha256}"
            );

            // Update signature data with SHA-256 and Cert ID for the template
            $signatureData['sha256'] = $appSha256;
            $signatureData['certificate_id'] = $certId;
            $signatureData['application_version'] = $application->application_version ?? '1.0';

            // 5. Generate Signature Certificate
            $templateData = SignatureCertificateService::getTemplateData($application, $brandingData, $signatureData);
            $certPdf = Pdf::loadView('pdf.signature-certificate', array_merge($brandingData, $templateData));
            $certPdfBinary = $certPdf->output();
            
            $pdfPath = 'funding-applications/' . $application->id . '/signature-certificate-' . $application->application_number . '.pdf';
            Storage::disk('public')->put($pdfPath, $certPdfBinary);
            
            $certSha256 = hash('sha256', $certPdfBinary);
            
            DownloadHistory::create([
                'user_id' => $user ? $user->id : null,
                'user_name' => $userName,
                'user_type' => $userType,
                'funding_application_id' => $application->id,
                'application_number' => $application->application_number,
                'application_version' => $application->application_version ?? '1.0',
                'certificate_id' => $certId,
                'file_type' => 'signature_certificate',
                'action' => 'generated',
                'sha256_hash' => $certSha256,
                'ip_address' => request()->ip(),
                'downloaded_at' => now(),
            ]);

            $this->activityLogService->logModelEvent(
                $application,
                ActivityLog::TYPE_ACTIVITY,
                ['visible_to_borrower' => '1'],
                'Borrower Electronic Signature Certificate Generated',
                "Borrower Electronic Signature Certificate PDF generated and locked. SHA-256: {$certSha256}"
            );

            // Update application with certificate path in signature_data
            $signatureData['certificate_path'] = $pdfPath;
            $application->update(['signature_data' => $signatureData]);

        } catch (\Exception $e) {
            Log::error('PDF Generation failed: ' . $e->getMessage());
        }
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
        
        $isHost = $authUser && ($authUser->user_type === User::ADMIN || $authUser->user_type === User::EMPLOYEE || (isset($authUser->partner) && (int)($authUser->partner->is_host ?? 0) === 1));
        $isPartner = $authUser && $authUser->user_type === User::PARTNER && !$isHost;

        $passwordText = !empty($password) ? 'with password protection' : 'without password protection';
        $this->activityLogService->logModelEvent(
            $application, 
            ActivityLog::TYPE_ACTIVITY,
            [
                'visible_to_borrower' => ($isHost || $isPartner) ? '0' : '1',
                'is_internal' => $isHost ? true : false,
            ],
            'Funding Application Downloaded',
            "Funding Application PDF downloaded {$passwordText}."
        );
        
        $pdfBinary = $pdf->output();
        $sha256 = hash('sha256', $pdfBinary);
        
        $savedPath = 'funding-applications/' . $application->id . '/downloads/' . $filename;
        Storage::disk('public')->put($savedPath, $pdfBinary);

        $userName = $authUser ? trim($authUser->first_name . ' ' . $authUser->last_name) : 'System';
        if (!$userName) $userName = 'System';
        $userType = $authUser->user_type ?? 'system';

        DownloadHistory::create([
            'user_id' => $authUser->id,
            'user_name' => $userName,
            'user_type' => $userType,
            'funding_application_id' => $application->id,
            'application_number' => $application->application_number,
            'application_version' => $application->application_version ?? '1.0',
            'certificate_id' => $brandingData['certId'] ?? null,
            'file_type' => 'application_pdf',
            'action' => !empty($password) ? 'downloaded_with_password' : 'downloaded_without_password',
            'sha256_hash' => $sha256,
            'ip_address' => request()->ip(),
            'downloaded_at' => now(),
            'details' => array_filter([
                'password' => $password,
                'file_path' => $savedPath
            ]),
        ]);
        
        return response($pdfBinary, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function downloadSignaturePdf($id, $password = null)
    {
        $authUser = Auth::user();

        $application = FundingApplication::query()
            ->when($authUser->user_type == User::PARTNER, function($query) use($authUser){
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

        $isHost = $authUser && ($authUser->user_type === User::ADMIN || $authUser->user_type === User::EMPLOYEE || (isset($authUser->partner) && (int)($authUser->partner->is_host ?? 0) === 1));
        $isPartner = $authUser && $authUser->user_type === User::PARTNER && !$isHost;

        $passwordText = !empty($password) ? 'with password protection' : 'without password protection';
        $this->activityLogService->logModelEvent(
            $application, 
            ActivityLog::TYPE_ACTIVITY,
            [
                'visible_to_borrower' => ($isHost || $isPartner) ? '0' : '1',
                'is_internal' => $isHost ? true : false,
            ],
            'Borrower Electronic Signature Certificate Downloaded',
            "Borrower Electronic Signature Certificate PDF downloaded {$passwordText}."
        );

        $pdfBinary = $pdf->output();
        $sha256 = hash('sha256', $pdfBinary);
        
        $savedPath = 'storage/funding-applications/' . $application->id . '/downloads/' . $filename;
        Storage::disk('public')->put($savedPath, $pdfBinary);

        $userName = $authUser ? trim($authUser->name) : 'System';
        if (!$userName) $userName = 'System';
        $userType = $authUser->user_type ?? 'system';

        DownloadHistory::create([
            'user_id' => $authUser->id,
            'user_name' => $userName,
            'user_type' => $userType,
            'funding_application_id' => $application->id,
            'application_number' => $application->application_number,
            'application_version' => $application->application_version ?? '1.0',
            'certificate_id' => $brandingData['certId'] ?? null,
            'file_type' => 'signature_certificate',
            'action' => !empty($password) ? 'downloaded_with_password' : 'downloaded_without_password',
            'sha256_hash' => $sha256,
            'ip_address' => request()->ip(),
            'downloaded_at' => now(),
            'details' => array_filter([
                'password' => $password,
                'file_path' => $savedPath
            ]),
        ]);

        return response($pdfBinary, 200)
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

        $isHost = $authUser && ($authUser->user_type === User::ADMIN || $authUser->user_type === User::EMPLOYEE || (isset($authUser->partner) && (int)($authUser->partner->is_host ?? 0) === 1));
        $isPartner = $authUser && $authUser->user_type === User::PARTNER && !$isHost;

        $passwordText = !empty($password) ? 'with password protection' : 'without password protection';
        $this->activityLogService->logModelEvent(
            $application, 
            ActivityLog::TYPE_ACTIVITY,
            [
                'visible_to_borrower' => ($isHost || $isPartner) ? '0' : '1',
                'is_internal' => $isHost ? true : false,
            ],
            'Funding Documents ZIP Downloaded',
            "Funding Documents ZIP downloaded {$passwordText}."
        );

        // Clean up temp PDF files
        @unlink($appTempFile);
        @unlink($sigTempFile);

        // Redundant log call removed

        $zipBinary = file_get_contents($zipTempFile);
        $sha256 = hash('sha256', $zipBinary);

        $savedPath = 'funding-applications/' . $application->id . '/downloads/' . $zipFilename;
        Storage::disk('public')->put($savedPath, $zipBinary);

        $brandingData = self::getBrandingData($application);
        $userName = $authUser ? trim($authUser->first_name . ' ' . $authUser->last_name) : 'System';
        if (!$userName) $userName = 'System';
        $userType = $authUser->user_type ?? 'system';

        DownloadHistory::create([
            'user_id' => $authUser->id,
            'user_name' => $userName,
            'user_type' => $userType,
            'funding_application_id' => $application->id,
            'application_number' => $application->application_number,
            'application_version' => $application->application_version ?? '1.0',
            'certificate_id' => $brandingData['certId'] ?? null,
            'file_type' => 'application_zip',
            'action' => !empty($password) ? 'downloaded_with_password' : 'downloaded_without_password',
            'sha256_hash' => $sha256,
            'ip_address' => request()->ip(),
            'downloaded_at' => now(),
            'details' => array_filter([
                'password' => $password,
                'file_path' => $savedPath
            ]),
        ]);

        return response()->download($zipTempFile, $zipFilename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function downloadFromHistory($historyId)
    {
        $history = DownloadHistory::find($historyId);
        if (!$history) {
            return ApiResponse::error('History record not found.');
        }

        $details = $history->details;
        if (empty($details['file_path'])) {
            return ApiResponse::error('File path not found for this record.');
        }

        $path = $details['file_path'];
        if (!Storage::disk('public')->exists($path)) {
            return ApiResponse::error('File not found on server.');
        }

        $filename = basename($path);
        
        $mime = 'application/pdf';
        if (Str::endsWith($path, '.zip')) {
            $mime = 'application/zip';
        }

        return response(Storage::disk('public')->get($path), 200)
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
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
            'tz' => request()->header('X-Timezone') ?? config('app.timezone'),
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

        // Certificate identifier — reuse the persisted certificate record if one already exists
        $certId = 'CERT-' . $currentTime->format('Ymd') . '-' . str_pad($application->id, 4, '0', STR_PAD_LEFT);

        $appVersion = $application->application_version ?? '1.0';

        $appPurpose = $application->purpose_of_funding
            ?? optional($application->fundingSequence)->uses
            ?? 'Funding Application';
        if (is_array($appPurpose)) {
            $appPurpose = implode(', ', $appPurpose);
        }
        $appPurpose = ucfirst(str_replace('_', ' ', (string) $appPurpose));

        $submissionDate = !empty($application->created_at)
            ? Carbon::parse($application->created_at)->timezone($tz)->format('M j, Y')
            : $sigDateStr;
        
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
        $p_monitor = 'M528 0H48A48 48 0 0 0 0 48v320a48 48 0 0 0 48 48h192l-16 48h-72a24 24 0 0 0 0 48h272a24 24 0 0 0 0-48h-72l-16-48h192a48 48 0 0 0 48-48V48a48 48 0 0 0-48-48zm-16 352H64V64h448z';
        $p_folder = 'M64 480H448c35.3 0 64-28.7 64-64V160c0-35.3-28.7-64-64-64H288c-10.1 0-19.6-4.7-25.6-12.8L243.2 57.6C231.1 41.5 212.1 32 192 32H64C28.7 32 0 60.7 0 96V416c0 35.3 28.7 64 64 64z';
        $p_check = 'M173.898 439.404l-166.4-166.4c-9.997-9.997-9.997-26.206 0-36.204l36.203-36.204c9.997-9.998 26.207-9.998 36.204 0L192 312.69 432.095 72.596c9.997-9.997 26.207-9.997 36.204 0l36.203 36.204c9.997 9.997 9.997 26.206 0 36.204l-294.4 294.401c-9.998 9.997-26.207 9.997-36.204-.001z';

        $fa_doc_white    = $icon_white($p_file, '0 0 384 512');
        $fa_cal_white    = $icon_white($p_cal);
        $fa_folder_white = $icon_white($p_folder);
        $fa_check        = $icon_white($p_check);

        // Section-icon badges render on a light #f0f5ff background in both designs,
        // so they need to be colored with the primary color to be visible!
        $fa_doc          = $icon_primary($p_building);
        $fa_users        = $icon_primary($p_user, '0 0 448 512');
        $fa_user         = $fa_users;
        $fa_shield       = $icon_primary($p_shield);
        $fa_shield_white = $icon_white($p_shield);
        $fa_monitor      = $icon_primary($p_monitor, '0 0 576 512');
        $fa_dollar       = $icon_primary($p_dollar);

        return [
            'appId' => $appId,
            'certId' => $certId,
            'appVersion' => $appVersion,
            'appPurpose' => $appPurpose,
            'submissionDate' => $submissionDate,
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
            'fa_folder_white' => $fa_folder_white,
            'fa_check' => $fa_check,
            'fa_doc' => $fa_doc,
            'fa_users' => $fa_users,
            'fa_user' => $fa_user,
            'fa_dollar' => $fa_dollar,
            'fa_shield' => $fa_shield,
            'fa_shield_white' => $fa_shield_white,
            'fa_monitor' => $fa_monitor,
        ];
    }

}

