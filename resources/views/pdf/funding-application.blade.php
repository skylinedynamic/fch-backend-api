<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Funding Application</title>
@php
    // Branding and company details passed dynamically from controller/service
    $primaryColor = $primaryColor ?? '#2563a2';
    $secondaryColor = $secondaryColor ?? '#D4AF37';
    $companyName = $companyName ?? 'Four Corner Funding';
    $companyAddress = $companyAddress ?? '400 Spectrum Center Drive, Suite 300';
    $companyCityState = $companyCityState ?? 'Irvine, CA 92618, USA';
    $companyContact = $companyContact ?? '(949) 555-0123  |  support@fourcornerfunding.com';
    $companyWebsite = $companyWebsite ?? 'www.fourcornerfunding.com';
    $logoBase64 = $logoBase64 ?? '';

    // Mapped borrower columns
    $businessName = $businessName ?? 'N/A';
    $businessPhone = $businessPhone ?? 'N/A';
    $businessAddress = $businessAddress ?? 'N/A';
    $businessCity = $businessCity ?? 'N/A';
    $personalCreditScore = $personalCreditScore ?? 'N/A';
    $businessEin = $businessEin ?? 'N/A';
    $businessEmail = $businessEmail ?? 'N/A';
    $businessCountry = $businessCountry ?? 'N/A';
    $businessState = $businessState ?? 'N/A';
    $businessZipCode = $businessZipCode ?? 'N/A';
    $businessStartDate = $businessStartDate ?? 'N/A';
    $businessCreditScore = $businessCreditScore ?? 'N/A';
    $businessIndustryType = $businessIndustryType ?? 'N/A';

    $firstName = $firstName ?? 'N/A';
    $lastName = $lastName ?? 'N/A';
    $email = $email ?? 'N/A';
    $phoneNumber = $phoneNumber ?? 'N/A';
    $homeAddress = $homeAddress ?? 'N/A';
    $homeState = $homeState ?? 'N/A';
    $homeCity = $homeCity ?? 'N/A';
    $homeZipCode = $homeZipCode ?? 'N/A';
    $homeCountry = $homeCountry ?? 'N/A';
    $homePhone = $homePhone ?? 'N/A';
    $dateOfBirth = $dateOfBirth ?? 'N/A';
    $last4SSN = $last4SSN ?? 'N/A';
@endphp
<style>
    @page { margin: 30px; }
    body {
        font-family: 'DejaVu Sans', Arial, sans-serif;
        color: #333333;
        margin: 0;
        padding: 0;
        font-size: 10px;
    }
    .text-primary { color: {{ $primaryColor }}; }
    .text-success { color: #00a65a; }
    
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }

    /* Page Container */
    .page-container {
        border: 2px solid {{ $primaryColor }};
        position: relative;
    }
    
    /* Top Pagination */
    .page-number {
        text-align: right;
        font-size: 8px;
        color: #555;
        padding-bottom: 5px;
        padding-right: 25px;
    }

    /* Header */
    .header-table { padding: 10px 25px 20px 25px; }
    .header-table td { vertical-align: middle; }
    .doc-title {
        font-size: 20px;
        font-weight: bold;
        color: {{ $primaryColor }};
        text-align: right;
        text-transform: uppercase;
        margin-bottom: 4px;
        letter-spacing: -0.5px;
    }
    .doc-subtitle {
        font-size: 9px;
        color: {{ $secondaryColor }};
        font-weight: bold;
        text-align: right;
        text-transform: uppercase;
    }

    /* Blue Banner */
    .info-banner {
        background-color: {{ $primaryColor }};
        color: #ffffff;
        padding: 10px 25px;
    }
    .info-banner td { vertical-align: middle; color: #ffffff; }
    
    .icon-box {
        width: 22px;
        height: 22px;
        border: 1px solid #ffffff;
        border-radius: 4px;
        text-align: center;
    }
    .info-label { color: #8da4c4; font-size: 8px; }
    .info-value { font-size: 10px; font-weight: bold; }

    /* Content Area */
    .content-area { padding: 20px 25px; }
    
    /* Section Headers */
    .section-header { margin-top: 15px; margin-bottom: 5px; }
    .section-header td { vertical-align: middle; }
    
    .section-icon {
        background-color: {{ $primaryColor }};
        width: 20px;
        height: 20px;
        border-radius: 50%;
        text-align: center;
    }
    .section-title {
        color: {{ $primaryColor }};
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        padding-left: 8px;
    }
    .section-line {
        border-bottom: 2px solid {{ $primaryColor }};
        margin-bottom: 10px;
    }

    /* Data Tables */
    .data-table { margin-bottom: 15px; }
    .data-table td {
        padding: 7px 0;
        border-bottom: 1px solid #eeeeee;
        font-size: 9px;
        color: #333333;
    }
    .t-label {
        color: #777777;
        width: 40%;
        font-weight: normal;
    }
    .t-colon { width: 5%; color: #aaaaaa; text-align: center; }
    .t-value {
        color: #1a1a1a;
        width: 55%;
        font-weight: 500;
    }

    /* Signature Box */
    .signature-box {
        border: 1px solid #e0e6ed;
        background-color: #fcfcfc;
        padding: 10px;
        margin-bottom: 15px;
    }
    .sig-signed-by { font-size: 7px; color: {{ $primaryColor }}; font-weight: bold; text-transform: uppercase; }
    .sig-name {
        font-family: 'Times New Roman', serif;
        font-style: italic;
        font-size: 22px;
        color: {{ $primaryColor }};
        border-bottom: 1px solid {{ $primaryColor }};
        padding-bottom: 3px;
        margin: 3px 0;
    }
    .sig-type { font-size: 7.5px; color: #555; }
    
    .status-badge { border-left: 1px solid #e0e6ed; padding-left: 10px; text-align: center; }
    .status-icon {
        background-color: #00a65a; color: #fff;
        width: 20px; height: 20px; border-radius: 50%;
        text-align: center; margin: 0 auto 3px auto;
    }
    .status-text { color: #00a65a; font-weight: bold; font-size: 9px; }

    /* Consents Table */
    .consents-table td {
        padding: 10px 8px;
        border-bottom: 1px solid #e0e6ed;
        vertical-align: middle;
        font-size: 9px;
    }
    .consents-table tr:first-child td { border-top: 1px solid #e0e6ed; }
    .check-icon {
        background-color: #00a65a;
        width: 14px; height: 14px; border-radius: 50%;
        text-align: center;
    }

    /* Final Certification */
    .final-cert-text { font-size: 8.5px; line-height: 1.6; color: #333; padding-right: 20px; text-align: justify; }
    .final-sig-box { border: 1px dashed {{ $primaryColor }}; padding: 12px; text-align: center; border-radius: 6px; }
    .final-sig-name-italic {
        font-family: 'Times New Roman', serif; font-style: italic; font-size: 20px; color: {{ $primaryColor }};
        border-bottom: 1px solid {{ $primaryColor }}; padding-bottom: 2px; margin-bottom: 5px; display: inline-block; width: 80%;
    }
    .final-sig-bold { font-weight: bold; font-size: 10px; color: #111; margin-bottom: 3px; }
    .final-sig-detail { font-size: 8px; color: #333; margin-bottom: 2px; text-align: left; padding-left: 20px; }
    .final-sig-type { font-size: 8px; color: #555; margin-bottom: 5px; }

    /* Footer */
    .footer-section { border-top: 2px solid {{ $primaryColor }}; padding: 15px 25px; }
    .footer-seal {
        width: 60px; height: 60px; border: 2px dashed {{ $primaryColor }}; border-radius: 50%; text-align: center; margin: 0 auto;
    }
    .footer-seal-inner {
        width: 50px; height: 50px; border: 2px solid {{ $primaryColor }}; border-radius: 50%; margin: 3px auto 0 auto;
        padding-top: 10px; box-sizing: border-box;
    }
    .seal-text { font-size: 4px; font-weight: bold; color: {{ $primaryColor }}; }
    
    .footer-disclaimer { font-size: 8px; color: #555; line-height: 1.4; padding: 0 15px; text-align: justify; }
    .footer-company { font-size: 8px; color: #333; line-height: 1.5; }
    
    /* Bottom Bar */
    .bottom-bar { background-color: {{ $primaryColor }}; color: #ffffff; padding: 8px 25px; }
    .bottom-bar td { font-size: 8px; color: #ffffff; }

    /* Watermark */
    .watermark {
        position: fixed;
        top: 350px;
        left: 0;
        width: 100%;
        text-align: center;
        opacity: 0.05;
        z-index: -100;
        font-size: 65px;
        font-weight: bold;
        font-family: 'Helvetica', 'Arial', sans-serif;
        color: {{ $primaryColor }};
        white-space: nowrap;
        letter-spacing: 2px;
    }

    .page-break { page-break-after: always; }
</style>
</head>
<body>

@php
    $borrower = $application->borrower ?? null;
    $rawSigData = $application->signature_data ?? [];
    $sigData = is_string($rawSigData) ? json_decode($rawSigData, true) : $rawSigData;
    if(!is_array($sigData)) $sigData = [];
    
    $appId = $application->application_number ?? ('FUND-'.str_pad($application->id, 6, '0', STR_PAD_LEFT));
    
    $borrowerName = $borrower ? trim(($borrower->first_name ?? '') . ' ' . ($borrower->last_name ?? '')) : trim(($application->first_name ?? '') . ' ' . ($application->last_name ?? ''));
    if (empty($borrowerName)) {
        $borrowerName = $application->full_name ?? '';
    }
    
    $businessName = $borrower->business_name ?? ($application->business_name ?? 'N/A');
    $status = ucfirst(str_replace('_', ' ', $application->status ?? 'Pending'));
    
    $ip = $sigData['ip_address'] ?? '127.0.0.1';
    $browser = $sigData['browser_name'] ?? 'Chrome';
    $os = $sigData['operating_system'] ?? 'Windows';
    $device = $sigData['device_type'] ?? 'Desktop';
    
    $sigName = $sigData['signature_name'] ?? $borrowerName;
    if(empty($sigName)) $sigName = 'N/A';
    
    $sigTime = !empty($sigData['signed_at']) ? \Carbon\Carbon::parse($sigData['signed_at']) : now();
    $sigDateStr = $sigTime->format('M j, Y');
    $sigTimeStr = $sigTime->format('h:i:s A');
    $timezone = $sigData['timezone'] ?? 'Asia/Dhaka (UTC+06:00)';
    
    $termsVer = $sigData['terms_version'] ?? '1.0';
    $privacyVer = $sigData['privacy_version'] ?? '1.0';
    $creditVer = $sigData['credit_auth_version'] ?? '1.0';
    $sha = $sigData['sha256'] ?? 'N/A';
    
    // Dynamic logo resolved at top of document

    // Helper: create inline SVG icon base64 with dynamic fill color
    // WHITE icons  — for use on primary-colored banner background
    $icon_white = function(string $path, string $viewBox = '0 0 512 512') {
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '"><path fill="#ffffff" d="' . $path . '"/></svg>'
        );
    };
    // PRIMARY COLOR icons — for use on white section-header backgrounds
    $icon_primary = function(string $path, string $viewBox = '0 0 512 512') use ($primaryColor) {
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '"><path fill="' . $primaryColor . '" d="' . $path . '"/></svg>'
        );
    };

    // --- ICON PATHS (Font Awesome 5 Free paths) ---
    // fa-building  (Business Information)
    $p_building = 'M436 480h-20V24c0-13.3-10.7-24-24-24H56C42.7 0 32 10.7 32 24v456H12c-6.6 0-12 5.4-12 12v20h448v-20c0-6.6-5.4-12-12-12zM128 76c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12V76zm0 96c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm52 148h-40c-6.6 0-12-5.4-12-12v-40c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12zm76 160h-64v-84c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v84zm64-172c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40zm0-96c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40zm0-96c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12V76c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40z';
    // fa-user  (Owner Information)
    $p_user = 'M256 288c79.5 0 144-64.5 144-144S335.5 0 256 0 112 64.5 112 144s64.5 144 144 144zm128 32h-55.1c-22.2 10.2-46.9 16-72.9 16s-50.6-5.8-72.9-16H128C57.3 320 0 377.3 0 448v16c0 26.5 21.5 48 48 48h416c26.5 0 48-21.5 48-48v-16c0-70.7-57.3-128-128-128z';
    // fa-dollar-sign  (Financial)
    $p_dollar = 'M272 128c0-14.2-3.2-27.8-8.9-40h17.7c8.8 0 16-7.2 16-16V16c0-8.8-7.2-16-16-16h-48.1c-43 0-80.3 28-93.7 66.7C126.5 62.2 113.8 56 99.9 56c-41.2 0-67.2 44.9-46.4 80.3L120 248c8.1 13.6 22.6 22.2 38.5 23.7V320H128c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h30.5v32.4c-17.4 7.3-28.5 26.5-24.1 47.4 3.6 17 18.3 30.7 35.7 32.1C191.7 497.4 208 481.9 208 464v-80h30.5c26.3 0 47.5-21.3 47.5-47.5V320h.5c44.2 0 80-35.8 80-80 0-61.9-50.1-112-94-112zm-48 208h-32v-48h32v48z';
    // fa-shield-alt  (Funding Program)
    $p_shield = 'M466.5 83.7l-192-80a48.15 48.15 0 0 0-36.9 0l-192 80C27.7 91.1 16 108.6 16 128c0 198.5 114.5 335.7 221.5 380.3 11.8 4.9 25.1 4.9 36.9 0C381.5 463.7 496 326.5 496 128c0-19.4-11.7-36.9-29.5-44.3zm-47.2 114.2l-184 184c-6.2 6.2-16.4 6.2-22.6 0l-104-104c-6.2-6.2-6.2-16.4 0-22.6l22.6-22.6c6.2-6.2 16.4-6.2 22.6 0l70.1 70.1 150.1-150.1c6.2-6.2 16.4-6.2 22.6 0l22.6 22.6c6.3 6.2 6.3 16.4 0 22.6z';
    // fa-file-alt  (Document Reference - banner)
    $p_file = 'M224 136V0H24C10.7 0 0 10.7 0 24v464c0 13.3 10.7 24 24 24h336c13.3 0 24-10.7 24-24V160H248c-13.2 0-24-10.8-24-24zm64 236c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12v8zm0-64c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12v8zm0-72v8c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12zm96-114.1v6.1H256V0h6.1c6.4 0 12.5 2.5 17 7l97.9 98c4.5 4.5 7 10.6 7 16.9z';
    // fa-calendar-alt  (Document Generated - banner)
    $p_cal = 'M0 464c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48V192H0v272zm320-196c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm-128-128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm-128-128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-40zM400 64h-48V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H160V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H48C21.5 64 0 85.5 0 112v48h448v-48c0-26.5-21.5-48-48-48z';

    // Build the four icon variable pairs (white for banner, primary-colored for sections)
    $fa_doc_white    = $icon_white($p_file, '0 0 384 512');
    $fa_cal_white    = $icon_white($p_cal);
    $fa_doc          = $icon_primary($p_building);      // Building  → Business Info
    $fa_users        = $icon_primary($p_user, '0 0 448 512');  // User → Owner Info
    $fa_dollar       = $icon_primary($p_dollar);        // Dollar   → Financial
    $fa_shield       = $icon_primary($p_shield);        // Shield   → Funding Program


    // Fallback to full_name if available
    $borrowerName = $application->full_name ?? trim(($application->first_name ?? '') . ' ' . ($application->last_name ?? ''));
@endphp

    <div class="watermark">
        <table style="width: 100%; text-align: center; border-collapse: collapse;">
            <tr>
                <td style="text-align: center; vertical-align: middle;">
                    @if($logoBase64)
                        <img src="{{ $logoBase64 }}" width="500" style="vertical-align: middle;">
                    @endif
                </td>
            </tr>
        </table>
    </div>

<!-- ================== PAGE 1 ================== -->
<div style="padding-top:10px;"></div>

<div class="page-container">
    <!-- Header -->
    <table class="header-table" style="padding: 20px 25px 15px 25px; width: 100%;">
        <tr>
            <td style="width: 30%; vertical-align: middle;">
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" width="150" alt="Logo">
                @else
                    <div style="font-size: 16px; font-weight: bold; color: {{ $primaryColor }};">{{ $companyName }}</div>
                @endif
            </td>
            <td style="width: 70%; text-align: right; vertical-align: middle; white-space: nowrap;">
                <div class="doc-title" style="font-family: 'Helvetica', 'Arial', sans-serif; font-size: 25px; letter-spacing: -0.5px; margin-bottom: 2px; color: {{ $primaryColor }}; font-weight: bold;">FUNDING APPLICATION</div>
            </td>
        </tr>
    </table>

    <!-- Blue Banner -->
    <div style="position: relative; background-color: {{ $primaryColor }}; color: #ffffff;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 48%; padding: 10px 0 10px 25px; vertical-align: middle;">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 35px; vertical-align: middle;">
                                <div class="icon-box" style="border: 1px solid rgba(255,255,255,0.7); border-radius: 6px; width: 28px; height: 28px; line-height: 28px; text-align: center;">
                                    <img src="{{ $fa_doc_white }}" width="14" height="14" style="margin-top: 7px;">
                                </div>
                            </td>
                            <td style="vertical-align: middle;">
                                <div class="info-label" style="color: #8da4c4; font-size: 7.5px;">Document Reference:</div>
                                <div class="info-value" style="font-size: 11px; font-weight: bold; color: {{ $secondaryColor }};">{{ $appId }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
                
                <td style="width: 2%; border-left: 1px solid rgba(255,255,255,0.3); height: 30px;"></td>
 
                <td style="width: 45%; padding: 10px 0 10px 15px; vertical-align: middle;">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 35px; vertical-align: middle;">
                                <div class="icon-box" style="border: 1px solid rgba(255,255,255,0.7); border-radius: 6px; width: 28px; height: 28px; line-height: 28px; text-align: center;">
                                    <img src="{{ $fa_cal_white }}" width="14" height="14" style="margin-top: 7px;">
                                </div>
                            </td>
                            <td style="vertical-align: middle;">
                                <div class="info-label" style="color: #8da4c4; font-size: 7.5px;">Document Generated:</div>
                                <div class="info-value" style="font-size: 11px; font-weight: bold; color: #ffffff;">{{ now()->format('M j, Y') }} &nbsp;|&nbsp; {{ now()->format('H:i:s') }} UTC</div>
                            </td>
                        </tr>
                    </table>
                </td>

                <td style="width: 5%; vertical-align: bottom; padding: 0; text-align: right;"></td>
            </tr>
        </table>
    </div>

    <!-- Content Area -->
    <div class="content-area">
        
        <!-- Business Information -->
        <table class="section-header">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_doc }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">BUSINESS INFORMATION</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Legal Business Name</td><td class="t-colon">:</td><td class="t-value">{{ $businessName }}</td></tr>
                        <tr><td class="t-label">Phone Number</td><td class="t-colon">:</td><td class="t-value">{{ $businessPhone }}</td></tr>
                        <tr><td class="t-label">Business Address</td><td class="t-colon">:</td><td class="t-value">{{ $businessAddress }}</td></tr>
                        <tr><td class="t-label">City</td><td class="t-colon">:</td><td class="t-value">{{ $businessCity }}</td></tr>
                        <tr><td class="t-label">Personal Credit Score</td><td class="t-colon">:</td><td class="t-value">{{ $personalCreditScore }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Business EIN</td><td class="t-colon">:</td><td class="t-value">{{ $businessEin }}</td></tr>
                        <tr><td class="t-label">Business Email</td><td class="t-colon">:</td><td class="t-value">{{ $businessEmail }}</td></tr>
                        <tr><td class="t-label">Country</td><td class="t-colon">:</td><td class="t-value">{{ $businessCountry }}</td></tr>
                        <tr><td class="t-label">State</td><td class="t-colon">:</td><td class="t-value">{{ $businessState }}</td></tr>
                        <tr><td class="t-label">Zip Code</td><td class="t-colon">:</td><td class="t-value">{{ $businessZipCode }}</td></tr>
                        <tr><td class="t-label">Business Start Date</td><td class="t-colon">:</td><td class="t-value">{{ $businessStartDate }}</td></tr>
                        <tr><td class="t-label">Industry Type</td><td class="t-colon">:</td><td class="t-value">{{ $businessIndustryType }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Owner Information -->
        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_users }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">PRIMARY INFORMATION</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">First Name</td><td class="t-colon">:</td><td class="t-value">{{ $firstName }}</td></tr>
                        <tr><td class="t-label">Email</td><td class="t-colon">:</td><td class="t-value">{{ $email }}</td></tr>
                        <tr><td class="t-label">Home Address</td><td class="t-colon">:</td><td class="t-value">{{ $homeAddress }}</td></tr>
                        <tr><td class="t-label">State</td><td class="t-colon">:</td><td class="t-value">{{ $homeState }}</td></tr>
                        <tr><td class="t-label">Zip Code</td><td class="t-colon">:</td><td class="t-value">{{ $homeZipCode }}</td></tr>
                        <tr><td class="t-label">Date of Birth</td><td class="t-colon">:</td><td class="t-value">{{ $dateOfBirth }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Last Name</td><td class="t-colon">:</td><td class="t-value">{{ $lastName }}</td></tr>
                        <tr><td class="t-label">Phone Number</td><td class="t-colon">:</td><td class="t-value">{{ $phoneNumber }}</td></tr>
                        <tr><td class="t-label">Country</td><td class="t-colon">:</td><td class="t-value">{{ $homeCountry }}</td></tr>
                        <tr><td class="t-label">City</td><td class="t-colon">:</td><td class="t-value">{{ $homeCity }}</td></tr>
                        <tr><td class="t-label">Home Phone</td><td class="t-colon">:</td><td class="t-value">{{ $homePhone }}</td></tr>
                        <tr><td class="t-label">Last 4 SSN</td><td class="t-colon">:</td><td class="t-value">{{ $last4SSN }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Financial & Funding Details -->
        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_dollar }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">FINANCIAL &amp; FUNDING DETAILS</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Currency</td><td class="t-colon">:</td><td class="t-value">{{ $application->currency ?: 'USD' }}</td></tr>
                        <tr><td class="t-label">Monthly Revenue</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->monthly_revenue) ? '$'.number_format((float)$application->monthly_revenue, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Annual Personal Income</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->annual_personal_income) ? '$'.number_format((float)$application->annual_personal_income, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Number of Monthly Deposits</td><td class="t-colon">:</td><td class="t-value">{{ $application->number_of_monthly_deposits ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Current Business Debt</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->current_business_debt) ? '$'.number_format((float)$application->current_business_debt, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Negative Days</td><td class="t-colon">:</td><td class="t-value">{{ $application->negative_days ?: 'N/A' }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%; vertical-align: top;">
                    <table class="data-table">
                        <tr><td class="t-label">Years in Business</td><td class="t-colon">:</td><td class="t-value">{{ $application->years_in_business ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Annual Revenue</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->annual_revenue) ? '$'.number_format((float)$application->annual_revenue, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Average Monthly Deposits</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->average_monthly_deposits) ? '$'.number_format((float)$application->average_monthly_deposits, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Average Daily Balance (ADB)</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->average_daily_balance) ? '$'.number_format((float)$application->average_daily_balance, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">NSF Count</td><td class="t-colon">:</td><td class="t-value">{{ $application->nsf_count ?: 'N/A' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

    </div><!-- close content-area page 1 -->
</div><!-- close page-container page 1 -->

<!-- Page Break -->
<div class="page-break"></div>

<!-- PAGE 2 - Funding Program Details -->
<div class="page-container">
    <div class="content-area">

        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_shield }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">FUNDING PROGRAM DETAILS</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Funding Type</td><td class="t-colon">:</td><td class="t-value">{{ $application->funding_type ?: ($application->preferred_funding_type ?: 'N/A') }}</td></tr>
                        <tr><td class="t-label">Funding Sequence Name</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->funding_sequencing)->name ?: (optional($application->fundingSequence)->name ?: 'N/A') }}</td></tr>
                        <tr><td class="t-label">Terms</td><td class="t-colon">:</td><td class="t-value">{{ $application->terms ?: (optional($application->funding_sequencing)->terms ?: 'N/A') }}</td></tr>
                        <tr><td class="t-label">Estimated Speed</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->funding_sequencing)->speed_to_funds ?: ($application->estimated_speed ?: 'N/A') }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Uses</td><td class="t-colon">:</td><td class="t-value">{{ $application->uses ?: (optional($application->funding_sequencing)->uses ?: 'N/A') }}</td></tr>
                        <tr><td class="t-label">Use of Funds</td><td class="t-colon">:</td><td class="t-value">{{ $application->use_of_funds ?: ($application->purpose_of_funds ?: 'N/A') }}</td></tr>
                        <tr><td class="t-label">Estimated Amount</td><td class="t-colon">:</td><td class="t-value">{{ optional($application->funding_sequencing)->amount_range ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Funding Request Status</td><td class="t-colon">:</td><td class="t-value"><strong>{{ $application->status ?: 'N/A' }}</strong></td></tr>
                    </table>
                </td>
            </tr>
        </table>

    </div>

    <!-- Footer -->
    <div class="footer-section">
        <table>
            <tr>
                <td style="width: 60%;">
                    <div class="footer-disclaimer">
                        This document is a record of the Funding Application submitted to {{ $companyName }}. The information contained herein is considered confidential and is strictly for underwriting and evaluation purposes.
                    </div>
                </td>
                <td style="width: 40%; text-align: right;">
                    <div class="footer-company">
                        <strong>{{ $companyName }}</strong><br>
                        {{ $companyAddress }}<br>
                        {{ $companyCityState }}<br>
                        {{ $companyContact }}<br>
                        {{ $companyWebsite }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Bottom Bar -->
    <div class="bottom-bar">
        <table>
            <tr>
                <td style="text-align: left;">&copy; {{ date('Y') }} {{ $companyName }}. All Rights Reserved.</td>
                <td style="text-align: right;">Ref: {{ $appId }} &nbsp;|&nbsp; {{ now()->format('M j, Y') }}</td>
            </tr>
        </table>
    </div>

</div>

</body>
</html>
