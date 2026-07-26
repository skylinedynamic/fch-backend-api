<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Electronic Signature Certificate</title>
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

    // Timezone configuration from request
    $tz = request()->header('X-Timezone') ?? config('app.timezone');
    $currentTime = now()->timezone($tz);
    $timezoneAbbr = $currentTime->format('T');
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
    .footer-section { border-top: 2px solid {{ $primaryColor }}; padding: 15px 25px 15px 25px; }
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
        top: 250px;
        left: 10%;
        opacity: 0.05;
        z-index: -100;
    }

    .page-break { page-break-after: always; }
</style>
</head>
<body>



@if($logoBase64)
    <div class="watermark">
        <img src="{{ $logoBase64 }}" width="550" alt="Watermark">
    </div>
@endif

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
                <div class="doc-title" style="font-family: 'Helvetica', 'Arial', sans-serif; font-size: 25px; letter-spacing: -0.5px; margin-bottom: 2px; color: {{ $primaryColor }}; font-weight: bold;">ELECTRONIC SIGNATURE CERTIFICATE</div>
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
                                <div class="info-label" style="font-size: 11px; font-weight: bold; color: #ffffff;">Document Reference:</div>
                                <div class="info-value" style="font-size: 11px; font-weight: bold; color: #ffffff;">{{ $appId }}</div>
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
                                <div class="info-label" style="font-size: 11px; font-weight: bold; color: #ffffff;">Certificate Generated:</div>
                                <div class="info-value" style="font-size: 11px; font-weight: bold; color: #ffffff;">{{ $currentTime->format('M j, Y') }}</div>
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
        <table style="table-layout: fixed;">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <!-- Cert Info -->
                    <table class="section-header">
                        <tr>
                            <td style="width: 25px;">
                                <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                                    <img src="{{ $fa_doc }}" width="14" height="14" style="margin-top: 6px;">
                                </div>
                            </td>
                            <td class="section-title">CERTIFICATE INFORMATION</td>
                        </tr>
                    </table>
                    <div class="section-line"></div>
                    
                    <table class="data-table">
                        <tr><td class="t-label">Application ID</td><td class="t-colon">:</td><td class="t-value">{{ $appId }}</td></tr>
                        <tr><td class="t-label">Borrower Name</td><td class="t-colon">:</td><td class="t-value">{{ $borrowerName }}</td></tr>
                        <tr><td class="t-label">Business Name</td><td class="t-colon">:</td><td class="t-value">{{ $businessName }}</td></tr>
                        <tr><td class="t-label">Status</td><td class="t-colon">:</td><td class="t-value">{{ $status }}</td></tr>
                    </table>

                    <!-- Doc Versions -->
                    <table class="section-header">
                        <tr>
                            <td style="width: 25px;">
                                <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                                    <img src="{{ $fa_shield }}" width="14" height="14" style="margin-top: 6px;">
                                </div>
                            </td>
                            <td class="section-title">DOCUMENT &amp; POLICY VERSIONS</td>
                        </tr>
                    </table>
                    <div class="section-line"></div>
                    
                    <table class="data-table">
                        <tr><td class="t-label">Terms Version</td><td class="t-colon">:</td><td class="t-value">{{ $termsVer }}</td></tr>
                        <tr><td class="t-label">Privacy Policy Version</td><td class="t-colon">:</td><td class="t-value">{{ $privacyVer }}</td></tr>
                        <tr><td class="t-label">Credit Auth Version</td><td class="t-colon">:</td><td class="t-value">{{ $creditVer }}</td></tr>
                    </table>
                </td>
                
                <!-- Spacer -->
                <td style="width: 4%;"></td>

                <td style="width: 48%; padding-left: 2%;">
                    <!-- Device Info -->
                    <table class="section-header">
                        <tr>
                            <td style="width: 25px;">
                                <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                                    <img src="{{ $fa_monitor }}" width="14" height="14" style="margin-top: 6px;">
                                </div>
                            </td>
                            <td class="section-title">DEVICE INFORMATION</td>
                        </tr>
                    </table>
                    <div class="section-line"></div>
                    
                    <table class="data-table">
                        <tr><td class="t-label">Public IP</td><td class="t-colon">:</td><td class="t-value">{{ $ip }}</td></tr>
                        <tr><td class="t-label">Browser</td><td class="t-colon">:</td><td class="t-value">{{ $browser }}</td></tr>
                        <tr><td class="t-label">OS</td><td class="t-colon">:</td><td class="t-value">{{ $os }}</td></tr>
                        <tr><td class="t-label">Device Type</td><td class="t-colon">:</td><td class="t-value">{{ $device }}</td></tr>
                    </table>

                    <!-- Additional Info -->
                    <table class="section-header">
                        <tr>
                            <td style="width: 25px;">
                                <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                                    <img src="{{ $fa_info }}" width="14" height="14" style="margin-top: 6px;">
                                </div>
                            </td>
                            <td class="section-title">ADDITIONAL INFORMATION</td>
                        </tr>
                    </table>
                    <div class="section-line"></div>
                    
                    <table class="data-table">
                        <tr><td class="t-label">ESIGN Consent Status</td><td class="t-colon">:</td><td class="t-value text-success"><strong>Accepted</strong></td></tr>
                        <tr><td class="t-label">Credit Auth Status</td><td class="t-colon">:</td><td class="t-value text-success"><strong>Accepted</strong></td></tr>
                        <tr><td class="t-label">Executed Application SHA-256 Hash</td><td class="t-colon">:</td><td class="t-value">{{ $sha }}</td></tr>
                        <tr><td class="t-label">Cert Generation</td><td class="t-colon">:</td><td class="t-value">{{ $currentTime->format('M j, Y h:i:s A') }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</div>

<div class="page-break"></div>

<!-- ================== PAGE 2 ================== -->
<div style="padding-top:10px;"></div>

<div class="page-container">

    <div class="content-area">
        <!-- Consents -->
        <table class="section-header">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_users }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">DETAILED CONSENTS &amp; AGREEMENTS</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table class="consents-table">
            <tr>
                <td style="width: 5%; text-align: center;">
                    <div class="check-icon">
                        <img src="{{ $fa_check }}" width="10" height="10" style="margin-top: 2px;">
                    </div>
                </td>
                <td style="width: 80%;">I certify that typing my name above constitutes my legally binding electronic signature under the ESIGN Act and applicable state laws.</td>
                <td style="width: 15%; text-align: right;" class="text-success"><strong>Accepted</strong></td>
            </tr>
            <tr>
                <td style="text-align: center;">
                    <div class="check-icon">
                        <img src="{{ $fa_check }}" width="10" height="10" style="margin-top: 2px;">
                    </div>
                </td>
                <td>I have read and agree to the Terms &amp; Conditions.</td>
                <td style="text-align: right;" class="text-success"><strong>Accepted</strong></td>
            </tr>
            <tr>
                <td style="text-align: center;">
                    <div class="check-icon">
                        <img src="{{ $fa_check }}" width="10" height="10" style="margin-top: 2px;">
                    </div>
                </td>
                <td>I authorize Four Corner Funding to obtain and review my business and/or personal credit information as described.</td>
                <td style="text-align: right;" class="text-success"><strong>Accepted</strong></td>
            </tr>
        </table>

        <!-- Final Certification -->
        <table class="section-header" style="margin-top: 25px;">
            <tr>
                <td style="width: 25px;">
                    <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                        <img src="{{ $fa_badge }}" width="14" height="14" style="margin-top: 6px;">
                    </div>
                </td>
                <td class="section-title">FINAL CERTIFICATION</td>
            </tr>
        </table>
        <div class="section-line"></div>
        
        <table>
            <tr>
                <td style="width: 60%; padding-right: 20px;">
                    <div class="final-cert-text">
                        By typing my full legal name above and clicking &ldquo;Submit Application,&rdquo; I certify that all information provided is true and accurate, consent to receive records electronically, authorize <strong>{{ $companyName }} and its funding partners</strong> to obtain and review my business and/or personal credit information as permitted by law, and agree that my electronic signature has the same legal effect as a handwritten signature under the ESIGN Act, UETA, and other applicable law.
                    </div>
                </td>
                <td style="width: 40%;">
                    <!-- Highly accurate signature card -->
                    <div class="final-sig-box">
                        <div class="final-sig-bold" style="border-bottom: 1px solid {{ $primaryColor }}; padding-bottom: 4px; margin-bottom: 8px; display: inline-block; width: 80%;">{{ $sigName }}</div>
                        <div class="final-sig-detail" style="margin-bottom: 5px; color: #555;"><strong>Signature Type:</strong> Typed Electronic Signature</div>
                        <div class="final-sig-detail" style="margin-bottom: 8px; color: #555;"><strong>Auth Method:</strong> User Account Login</div>
                        
                        <div class="final-sig-detail">
                            <img src="{{ $fa_cal_dark }}" width="8" height="8" style="vertical-align: middle; margin-right: 3px;">
                            {{ $sigDateStr }}
                        </div>
                        <div class="final-sig-detail">
                            <img src="{{ $fa_clock_dark }}" width="8" height="8" style="vertical-align: middle; margin-right: 3px;">
                            {{ $sigTimeStr }}
                        </div>
                        <div class="final-sig-detail">
                            <img src="{{ $fa_globe_dark }}" width="8" height="8" style="vertical-align: middle; margin-right: 3px;">
                            {{ $timezone }}
                        </div>
                    </div>
                </td>
            </tr>
        </table>
        
    </div>

    <!-- Footer -->
    <div class="footer-section">
        <table style="width: 100%;">
            <tr>
                <td style="width: 15%; text-align: center; vertical-align: middle; padding: 10px 0;">
                    <img src="{{ $custom_seal }}" width="60" height="60" style="vertical-align: middle; display: inline-block;">
                </td>
                <td style="width: 50%; vertical-align: middle; padding: 10px 0;">
                    <div class="footer-disclaimer">
                        This document serves as proof of electronic signature under the ESIGN Act, UETA, and applicable federal and state laws. It is a tamper-evident record of the signer's intent to sign this Funding Application electronically.
                    </div>
                </td>
                <td style="width: 35%; text-align: right; vertical-align: middle; padding: 10px 0;">
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
        <div style="height: 10px; clear: both;"></div>
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