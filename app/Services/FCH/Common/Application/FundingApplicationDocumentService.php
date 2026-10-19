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
     * Keep the application exactly as signed, with its certificate.
     *
     * Every signing is a new version of the application - 1, 2, 3 - with its own
     * folder, funding-applications/{id}/signed/v{n}-{certificate id}/, holding
     * the application PDF as signed and the certificate for it. A later edit
     * and re-signing adds the next version; nothing already signed is ever
     * overwritten. The signature record carries the version, the SHA-256 of the
     * signed application PDF, both paths, and who the application was for and
     * its status at that moment, so the certificate does not follow later edits.
     */
    public function generateAndLockFinalDocuments(FundingApplication $application, array &$signatureData)
    {
        try {
            $brandingData = self::getBrandingData($application);

            $version = DownloadHistory::where('funding_application_id', $application->id)
                ->where('file_type', 'application_pdf')
                ->where('action', 'generated')
                ->count() + 1;
            $certId = (string) Str::uuid();
            $folder = "funding-applications/{$application->id}/signed/v{$version}-{$certId}";

            // The application as signed.
            $appPdfBinary = Pdf::loadView('pdf.funding-application', array_merge(['application' => $application], $brandingData))->output();
            $appSha256 = hash('sha256', $appPdfBinary);
            $appPdfPath = "{$folder}/application.pdf";
            Storage::disk('public')->put($appPdfPath, $appPdfBinary);

            $user = Auth::user();
            $history = [
                'user_id' => $user?->id,
                'user_name' => $user ? trim((string) $user->name) : '',
                'user_type' => $user->user_type ?? '',
                'funding_application_id' => $application->id,
                'application_number' => $application->application_number,
                'application_version' => (string) $version,
                'certificate_id' => $certId,
                'action' => 'generated',
                'ip_address' => request()->ip(),
                'downloaded_at' => now(),
            ];
            DownloadHistory::create($history + ['file_type' => 'application_pdf', 'sha256_hash' => $appSha256]);

            // No activity-log entry for generating the PDFs: it happens on every
            // submission, and the submit / re-submit entry already says so. The
            // generation itself is recorded in the download history.

            $signatureData = array_merge($signatureData, [
                'application_version' => $version,
                'certificate_id' => $certId,
                'sha256' => $appSha256,
                'signed_application_path' => $appPdfPath,
                // Frozen with the signature, so the certificate does not follow
                // later edits to the application.
                'borrower_name' => personName($application->first_name, $application->middle_name ?? null, $application->last_name) ?: null,
                'business_name' => $application->business_name,
                'status_at_signing' => $application->status,
            ]);

            // The certificate for that signed version.
            $templateData = SignatureCertificateService::getTemplateData($application, $brandingData, $signatureData);
            $certPdfBinary = Pdf::loadView('pdf.signature-certificate', array_merge($brandingData, $templateData))->output();
            $certPath = "{$folder}/signature-certificate.pdf";
            Storage::disk('public')->put($certPath, $certPdfBinary);
            DownloadHistory::create($history + ['file_type' => 'signature_certificate', 'sha256_hash' => hash('sha256', $certPdfBinary)]);

            $signatureData['certificate_path'] = $certPath;
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
            "Funding Application PDF downloaded {$passwordText}.",
            $isHost ? ActivityLog::VISIBILITY_INTERNAL : ($isPartner ? ActivityLog::VISIBILITY_PARTNER : ActivityLog::VISIBILITY_EVERYONE)
        );
        
        $pdfBinary = $pdf->output();
        $sha256 = hash('sha256', $pdfBinary);
        
        $savedPath = 'funding-applications/' . $application->id . '/downloads/' . $filename;
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
            'application_version' => (string) ($application->signature_data['application_version'] ?? '1.0'),
            'certificate_id' => $brandingData['certId'] ?? null,
            'file_type' => 'application_pdf',
            'action' => !empty($password) ? 'downloaded_with_password' : 'downloaded_without_password',
            'sha256_hash' => $sha256,
            'ip_address' => request()->ip(),
            'downloaded_at' => now(),
            'details' => array_filter(array_merge([
                'password' => $password,
                'file_path' => $savedPath
            ], $this->getDeviceInfo())),
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
            "Borrower Electronic Signature Certificate PDF downloaded {$passwordText}.",
            $isHost ? ActivityLog::VISIBILITY_INTERNAL : ($isPartner ? ActivityLog::VISIBILITY_PARTNER : ActivityLog::VISIBILITY_EVERYONE)
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
            'application_version' => (string) ($application->signature_data['application_version'] ?? '1.0'),
            'certificate_id' => $brandingData['certId'] ?? null,
            'file_type' => 'signature_certificate',
            'action' => !empty($password) ? 'downloaded_with_password' : 'downloaded_without_password',
            'sha256_hash' => $sha256,
            'ip_address' => request()->ip(),
            'downloaded_at' => now(),
            'details' => array_filter(array_merge([
                'password' => $password,
                'file_path' => $savedPath
            ], $this->getDeviceInfo())),
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
            "Funding Documents ZIP downloaded {$passwordText}.",
            $isHost ? ActivityLog::VISIBILITY_INTERNAL : ($isPartner ? ActivityLog::VISIBILITY_PARTNER : ActivityLog::VISIBILITY_EVERYONE)
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
        $userName = $authUser ? trim($authUser->name) : 'System';
        if (!$userName) $userName = 'System';
        $userType = $authUser->user_type ?? 'system';

        DownloadHistory::create([
            'user_id' => $authUser->id,
            'user_name' => $userName,
            'user_type' => $userType,
            'funding_application_id' => $application->id,
            'application_number' => $application->application_number,
            'application_version' => (string) ($application->signature_data['application_version'] ?? '1.0'),
            'certificate_id' => $brandingData['certId'] ?? null,
            'file_type' => 'application_zip',
            'action' => !empty($password) ? 'downloaded_with_password' : 'downloaded_without_password',
            'sha256_hash' => $sha256,
            'ip_address' => request()->ip(),
            'downloaded_at' => now(),
            'details' => array_filter(array_merge([
                'password' => $password,
                'file_path' => $savedPath
            ], $this->getDeviceInfo())),
        ]);

        return response()->download($zipTempFile, $zipFilename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function downloadFromHistory($historyId)
    {
        $history = DownloadHistory::whereHas('fundingApplication', fn ($applications) => $applications->reviewableBy(Auth::user()))
            ->find($historyId);
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

    /**
     * Whose brand goes on this document, and which logo file represents it.
     *
     * Always the host admin's: the funding application PDF and the electronic
     * signature certificate are the host's records, so they carry the host's
     * logo and company name whichever partner the application came through -
     * Certified and White-Label alike, even when the partner has uploaded a logo
     * of its own. The brand partner is forced to the host rather than left to
     * inheritance, which would otherwise hand a partner its own branding.
     *
     * The logo itself is logo_document - the key that means "the logo for a white
     * page". Falling back to logo_sidebar when it is absent is explicit here as
     * well as in AppConfiguration::BRANDING_LOGO_FALLBACKS.
     */
    private static function getCompanyBrandingInfo($application)
    {
        $appConfigService = new AppConfigurationService();

        $brandPartner = Partner::host()
            ?? ($application->partner_id ? Partner::find($application->partner_id) : null);
        $brandPartnerId = $brandPartner->id ?? null;

        // Resolves each surface logo through its fallback chain, exhausting the
        // tenant's own uploads before inheriting the host's.
        $branding = $appConfigService->getBrandingSettings($brandPartnerId);

        $address = $appConfigService->get('address', null, null, $brandPartnerId);
        $phone = $appConfigService->get('phone_number', null, null, $brandPartnerId);
        $email = $appConfigService->get('email', null, null, $brandPartnerId);
        $contactParts = array_filter([$phone, $email]);

        // logo_document is the on-white logo; logo_sidebar is the explicit
        // last resort the brief asks for when no document logo is set.
        $logoKey = $branding['logo_document'] ?? $branding['logo_sidebar'] ?? null;

        // The packaged logo files are the host's, so they are a valid last resort.
        [$logoBase64, $logoWidth, $logoHeight] = self::resolveDocumentLogo($logoKey);

        return [
            'primaryColor' => $branding['primary_color'] ?? '#2563a2',
            'secondaryColor' => $branding['secondary_color'] ?? '#D4AF37',
            'companyName' => $branding['site_name'] ?? config('app.name'),
            'companyAddress' => $address ?? '',
            'companyCityState' => '',
            'companyContact' => implode('  |  ', $contactParts),
            'companyWebsite' => '',
            'logoBase64' => $logoBase64,
            'logoWidth' => $logoWidth,
            'logoHeight' => $logoHeight,
            'tz' => request()->header('X-Timezone') ?? config('app.timezone'),
        ];
    }

    /**
     * Read the logo file and size it to fit a 200x60 box, preserving aspect ratio.
     *
     * dompdf does not implement object-fit (it is absent from dompdf 3.x
     * entirely), so declaring `object-fit: contain` in the template would be
     * silently ignored and a fixed 200x60 would stretch every non-3.33:1 logo.
     * The contain maths is therefore done here and emitted as explicit width and
     * height; the CSS declaration stays in the template for any browser preview.
     *
     * @return array{0: string, 1: int, 2: int} [base64 data URI, width, height]
     */
    private static function resolveDocumentLogo(
        ?string $logoKey,
        bool $allowPackagedFallback = true,
        int $boxWidth = 200,
        int $boxHeight = 60
    ): array {
        $paths = $logoKey && $logoKey !== 'null' ? [
            \Illuminate\Support\Facades\Storage::disk('public')->path(ltrim($logoKey, '/')),
            public_path('storage/' . ltrim($logoKey, '/')),
            public_path(ltrim($logoKey, '/')),
        ] : [];

        // The packaged files are the HOST's logo, so they are a valid last resort
        // only for host-branded documents.
        if ($allowPackagedFallback) {
            $paths = array_merge($paths, [public_path('logo-mail.png'), public_path('logo.png')]);
        }

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
            $base64 = 'data:image/' . $ext . ';base64,' . base64_encode(file_get_contents($path));

            $width = $boxWidth;
            $height = $boxHeight;

            $size = @getimagesize($path);
            if ($size && !empty($size[0]) && !empty($size[1])) {
                // contain: scale by whichever axis is the tighter constraint.
                $scale = min($boxWidth / $size[0], $boxHeight / $size[1]);
                $width = max(1, (int) round($size[0] * $scale));
                $height = max(1, (int) round($size[1] * $scale));
            }

            return [$base64, $width, $height];
        }

        return ['', $boxWidth, $boxHeight];
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
        $businessStartDate = toDateOnly($rawStartDate, 'M j, Y') ?? 'N/A';
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
        $dateOfBirth = toDateOnly($rawDob, 'M j, Y') ?? 'N/A';
        
        $ssn = self::ssnForDocument($application, $borrower);

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
            'ssn' => $ssn,
            'fundingUses' => $fundingUses,
            'timeInBusinessCount' => $timeInBusinessCount,
        ];
    }

    /**
     * The applicant's full SSN, written XXX-XX-XXXX: the application's own,
     * else the borrower's. An older account holding only the last four shows
     * those as ***-**-1234 - there is no full number to show.
     */
    private static function ssnForDocument($application, $borrower): string
    {
        foreach ([$application->ssn ?? null, $borrower?->ssn] as $candidate) {
            $digits = preg_replace('/\D/', '', (string) $candidate);
            if (strlen($digits) === 9) {
                return substr($digits, 0, 3) . '-' . substr($digits, 3, 2) . '-' . substr($digits, 5);
            }
        }

        $lastFour = preg_replace('/\D/', '', (string) ($borrower?->last_4_ssn ?? ''));

        return strlen($lastFour) === 4 ? '***-**-' . $lastFour : 'N/A';
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

        $appVersion = $sigData['application_version'] ?? '1.0';

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

    /**
     * Get device information from user agent
     */
    private function getDeviceInfo(): array
    {
        $agent = request()->header('User-Agent') ?? '';
        
        $os = 'Unknown';
        if (preg_match('/windows|win32|macintosh|mac os x|linux|android|iphone|ipad/i', $agent, $matches)) {
            $os = $matches[0];
        }

        $browser = 'Unknown';
        if (preg_match('/chrome|firefox|safari|edge|opera|msie|trident/i', $agent, $matches)) {
            $browser = $matches[0];
        }

        $browserVersion = 'Unknown';
        if ($browser !== 'Unknown') {
            if (preg_match('/version[\/ ]([\d.]+)/i', $agent, $matches)) {
                $browserVersion = $matches[1];
            } else if (preg_match('/' . preg_quote($browser, '/') . '[\/ ]([\d.]+)/i', $agent, $matches)) {
                $browserVersion = $matches[1];
            }
        }

        $deviceType = 'Desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $agent)) {
            $deviceType = 'Tablet';
        } else if (preg_match('/Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Kindle|NetFront|Silk-Accelerated|(hpw|web)OS|Fennec|Minimo|Opera M(obi|ini)|Blazer|Dolfin|Dolphin|Skyfire|Zune/i', $agent)) {
            $deviceType = 'Mobile';
        }

        return [
            'user_agent' => $agent,
            'operating_system' => $os,
            'browser_name' => $browser,
            'browser_version' => $browserVersion,
            'device_type' => $deviceType,
        ];
    }
}
