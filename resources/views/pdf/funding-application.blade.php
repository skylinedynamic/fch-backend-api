<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Funding Application</title>
<style>
    @page { margin: 30px; }
    body {
        font-family: 'DejaVu Sans', Arial, sans-serif;
        color: #333333;
        margin: 0;
        padding: 0;
        font-size: 10px;
    }
    .text-primary { color: #0d2f5c; }
    .text-success { color: #00a65a; }
    
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }

    /* Page Container */
    .page-container {
        border: 2px solid #0d2f5c;
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
        color: #0d2f5c;
        text-align: right;
        text-transform: uppercase;
        margin-bottom: 4px;
        letter-spacing: -0.5px;
    }
    .doc-subtitle {
        font-size: 9px;
        color: #2ba0c9;
        font-weight: bold;
        text-align: right;
        text-transform: uppercase;
    }

    /* Blue Banner */
    .info-banner {
        background-color: #0d2f5c;
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
        background-color: #0d2f5c;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        text-align: center;
    }
    .section-title {
        color: #0d2f5c;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        padding-left: 8px;
    }
    .section-line {
        border-bottom: 2px solid #0d2f5c;
        margin-bottom: 10px;
    }

    /* Data Tables */
    .data-table { margin-bottom: 15px; }
    .data-table td {
        padding: 6px 0;
        border-bottom: 1px solid #f0f0f0;
        font-size: 9px;
    }
    .t-label { color: #555555; width: 40%; }
    .t-colon { width: 5%; color: #777; text-align: center; }
    .t-value { color: #111111; width: 55%; }

    /* Signature Box */
    .signature-box {
        border: 1px solid #e0e6ed;
        background-color: #fcfcfc;
        padding: 10px;
        margin-bottom: 15px;
    }
    .sig-signed-by { font-size: 7px; color: #0d2f5c; font-weight: bold; text-transform: uppercase; }
    .sig-name {
        font-family: 'Times New Roman', serif;
        font-style: italic;
        font-size: 22px;
        color: #0d2f5c;
        border-bottom: 1px solid #0d2f5c;
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
    .final-sig-box { border: 1px dashed #0d2f5c; padding: 12px; text-align: center; border-radius: 6px; }
    .final-sig-name-italic {
        font-family: 'Times New Roman', serif; font-style: italic; font-size: 20px; color: #0d2f5c;
        border-bottom: 1px solid #0d2f5c; padding-bottom: 2px; margin-bottom: 5px; display: inline-block; width: 80%;
    }
    .final-sig-bold { font-weight: bold; font-size: 10px; color: #111; margin-bottom: 3px; }
    .final-sig-detail { font-size: 8px; color: #333; margin-bottom: 2px; text-align: left; padding-left: 20px; }
    .final-sig-type { font-size: 8px; color: #555; margin-bottom: 5px; }

    /* Footer */
    .footer-section { border-top: 2px solid #0d2f5c; padding: 15px 25px; }
    .footer-seal {
        width: 60px; height: 60px; border: 2px dashed #0d2f5c; border-radius: 50%; text-align: center; margin: 0 auto;
    }
    .footer-seal-inner {
        width: 50px; height: 50px; border: 2px solid #0d2f5c; border-radius: 50%; margin: 3px auto 0 auto;
        padding-top: 10px; box-sizing: border-box;
    }
    .seal-text { font-size: 4px; font-weight: bold; color: #0d2f5c; }
    
    .footer-disclaimer { font-size: 8px; color: #555; line-height: 1.4; padding: 0 15px; text-align: justify; }
    .footer-company { font-size: 8px; color: #333; line-height: 1.5; }
    
    /* Bottom Bar */
    .bottom-bar { background-color: #0d2f5c; color: #ffffff; padding: 8px 25px; }
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
        color: #0d2f5c;
        white-space: nowrap;
        letter-spacing: 2px;
    }

    .page-break { page-break-after: always; }
</style>
</head>
<body>

@php
    $rawSigData = $application->signature_data ?? [];
    $sigData = is_string($rawSigData) ? json_decode($rawSigData, true) : $rawSigData;
    if(!is_array($sigData)) $sigData = [];
    
    $appId = $application->application_number ?? ('FUND-'.str_pad($application->id, 6, '0', STR_PAD_LEFT));
    $borrowerName = trim(($application->first_name ?? '') . ' ' . ($application->last_name ?? ''));
    $businessName = $application->business_name ?? 'N/A';
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
    
    // Read horizontal logo for header and watermark
    $logoPath = public_path('logo-mail.png');
    if (!file_exists($logoPath)) {
        $logoPath = public_path('logo.png');
    }
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    }
    
    // FontAwesome SVGs Base64 encoded for guaranteed rendering in DOMPDF
    $fa_doc = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><path fill="#ffffff" d="M224 136V0H24C10.7 0 0 10.7 0 24v464c0 13.3 10.7 24 24 24h336c13.3 0 24-10.7 24-24V160H248c-13.2 0-24-10.8-24-24zm160 14.1v6.1H256V0h6.1c6.4 0 12.5 2.5 17 7l97.9 98c4.5 4.5 7 10.6 7 16.9z"/></svg>');
    $fa_users = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="#ffffff" d="M224 256c70.7 0 128-57.3 128-128S294.7 0 224 0 96 57.3 96 128s57.3 128 128 128zm89.6 32h-16.7c-22.2 10.2-46.9 16-72.9 16s-50.6-5.8-72.9-16h-16.7C60.2 288 0 348.2 0 422.4V464c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48v-41.6c0-74.2-60.2-134.4-134.4-134.4z"/></svg>');
    $fa_cal = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="#ffffff" d="M400 64h-48V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H160V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H48C21.5 64 0 85.5 0 112v352c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48V112c0-26.5-21.5-48-48-48zM48 160h352v288H48V160z"/></svg>');
    $fa_shield = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="#ffffff" d="M466.5 83.7l-192-80a48.1 48.1 0 0 0-36.9 0l-192 80C27.7 91.1 16 108.6 16 128c0 198.5 114.5 335.7 221.5 380.3 11.8 4.9 25.1 4.9 36.9 0C381.5 463.7 496 326.5 496 128c0-19.4-11.7-36.9-29.5-44.3zM256 464C161 423.5 64 304.7 64 128l192-80 192 80c0 176.7-97 295.5-192 336z"/></svg>');
    
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
                    <div style="font-size: 16px; font-weight: bold; color: #0d2f5c;">FOUR CORNER FUNDING</div>
                @endif
            </td>
            <td style="width: 70%; text-align: right; vertical-align: middle; white-space: nowrap;">
                <div class="doc-title" style="font-family: 'Helvetica', 'Arial', sans-serif; font-size: 25px; letter-spacing: -0.5px; margin-bottom: 2px; color: #0d2f5c; font-weight: bold;">FUNDING APPLICATION</div>
            </td>
        </tr>
    </table>

    <!-- Blue Banner -->
    <div style="position: relative; background-color: #0d2f5c; color: #ffffff;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 48%; padding: 10px 0 10px 25px; vertical-align: middle;">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 35px; vertical-align: middle;">
                                <div class="icon-box" style="border: 1px solid rgba(255,255,255,0.7); border-radius: 6px; width: 24px; height: 24px; line-height: 24px; text-align: center;">
                                    <img src="{{ $fa_doc }}" width="11" height="11" style="margin-top: 6px;">
                                </div>
                            </td>
                            <td style="vertical-align: middle;">
                                <span class="info-label" style="color: #8da4c4; font-size: 7.5px;">Document Reference:</span><br>
                                <span class="info-value" style="font-size: 11px; font-weight: bold; color: #ffffff;">{{ $appId }}</span>
                            </td>
                        </tr>
                    </table>
                </td>
                
                <td style="width: 2%; border-left: 1px solid #1a4276; height: 30px;"></td>

                <td style="width: 45%; padding: 10px 0 10px 15px; vertical-align: middle;">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 35px; vertical-align: middle;">
                                <div class="icon-box" style="border: 1px solid rgba(255,255,255,0.7); border-radius: 6px; width: 24px; height: 24px; line-height: 24px; text-align: center;">
                                    <img src="{{ $fa_cal }}" width="12" height="12" style="margin-top: 5px;">
                                </div>
                            </td>
                            <td style="vertical-align: middle;">
                                <span class="info-label" style="color: #8da4c4; font-size: 7.5px;">Document Generated:</span><br>
                                <span class="info-value" style="font-size: 11px; font-weight: bold; color: #ffffff;">{{ now()->format('M j, Y') }} &nbsp;|&nbsp; {{ now()->format('H:i:s') }} UTC</span>
                            </td>
                        </tr>
                    </table>
                </td>

                <td style="width: 5%; vertical-align: bottom; padding: 0; text-align: right;">
                    <!-- Cyan Triangle Wedge -->
                    <div style="width: 0; height: 0; border-bottom: 44px solid #00a0d1; border-left: 20px solid transparent; float: right;"></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Content Area -->
    <div class="content-area">
        
        <!-- Business Information -->
        <table class="section-header">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon">
                        <img src="{{ $fa_doc }}" width="10" height="10" style="margin-top: 5px;">
                    </div>
                </td>
                <td class="section-title">BUSINESS INFORMATION</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Application ID</td><td class="t-colon">:</td><td class="t-value">{{ $appId }}</td></tr>
                        <tr><td class="t-label">Business Name</td><td class="t-colon">:</td><td class="t-value">{{ $application->business_name ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Federal Tax ID / EIN</td><td class="t-colon">:</td><td class="t-value">{{ $application->federal_tax_id_ein ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Industry Type</td><td class="t-colon">:</td><td class="t-value">{{ $application->industry_type ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Address</td><td class="t-colon">:</td><td class="t-value">
                            {{ trim(($application->street_address ?? '') . ' ' . ($application->city ?? '') . ', ' . ($application->state ?? '') . ' ' . ($application->zip_code ?? '') . ' ' . ($application->country ?? '')) ?: 'N/A' }}
                        </td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Years in Business</td><td class="t-colon">:</td><td class="t-value">{{ $application->years_in_business ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Checking Start Date</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->business_checking_start_date) ? \Carbon\Carbon::parse($application->business_checking_start_date)->format('M j, Y') : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Status</td><td class="t-colon">:</td><td class="t-value"><strong>{{ $status }}</strong></td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Owner Information -->
        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon">
                        <img src="{{ $fa_users }}" width="10" height="10" style="margin-top: 5px;">
                    </div>
                </td>
                <td class="section-title">PRIMARY OWNER INFORMATION</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Full Name</td><td class="t-colon">:</td><td class="t-value">{{ $borrowerName ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Date of Birth</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->date_of_birth) ? \Carbon\Carbon::parse($application->date_of_birth)->format('M j, Y') : 'N/A' }}</td></tr>
                        <tr><td class="t-label">SSN</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->ssn) ? '***-**-'.substr($application->ssn, -4) : 'N/A' }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Phone Number</td><td class="t-colon">:</td><td class="t-value">{{ $application->phone_number ?: 'N/A' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Financial & Funding Details -->
        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon">
                        <img src="{{ $fa_cal }}" width="10" height="10" style="margin-top: 5px;">
                    </div>
                </td>
                <td class="section-title">FINANCIAL &amp; FUNDING DETAILS</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table style="table-layout: fixed; width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Monthly Revenue</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->monthly_revenue) ? '$'.number_format((float)$application->monthly_revenue, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Annual Revenue</td><td class="t-colon">:</td><td class="t-value">{{ !empty($application->annual_revenue) ? '$'.number_format((float)$application->annual_revenue, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Business Bank Name</td><td class="t-colon">:</td><td class="t-value">{{ $application->business_bank_name ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Collateral Available</td><td class="t-colon">:</td><td class="t-value">{{ $application->collateral_available ?: 'N/A' }}</td></tr>
                    </table>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; padding-left: 2%;">
                    <table class="data-table">
                        <tr><td class="t-label">Requested Amount</td><td class="t-colon">:</td><td class="t-value" style="font-weight: bold; color: #0d2f5c;">{{ !empty($application->requested_amount) ? '$'.number_format((float)$application->requested_amount, 2) : 'N/A' }}</td></tr>
                        <tr><td class="t-label">Purpose of Funds</td><td class="t-colon">:</td><td class="t-value">{{ $application->purpose_of_funds ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Preferred Funding Type</td><td class="t-colon">:</td><td class="t-value">{{ $application->preferred_funding_type ?: 'N/A' }}</td></tr>
                        <tr><td class="t-label">Timeframe Needed</td><td class="t-colon">:</td><td class="t-value">{{ $application->timeframe_needed ?: 'N/A' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Funding Program Details -->
        <table class="section-header" style="margin-top: 10px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon">
                        <img src="{{ $fa_shield }}" width="10" height="10" style="margin-top: 5px;">
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
                        This document is a record of the Funding Application submitted to Four Corner Funding. The information contained herein is considered confidential and is strictly for underwriting and evaluation purposes.
                    </div>
                </td>
                <td style="width: 40%; text-align: right;">
                    <div class="footer-company">
                        <strong>Four Corner Funding</strong><br>
                        400 Spectrum Center Drive, Suite 300<br>
                        Irvine, CA 92618, USA<br>
                        (949) 555-0123 &nbsp;|&nbsp; support@fourcornerfunding.com<br>
                        www.fourcornerfunding.com
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Bottom Bar -->
    <div class="bottom-bar">
        <table>
            <tr>
                <td style="text-align: left;">&copy; {{ date('Y') }} Four Corner Funding. All Rights Reserved.</td>
                <td style="text-align: right;">Funding Application Record</td>
            </tr>
        </table>
    </div>

</div>

</body>
</html>