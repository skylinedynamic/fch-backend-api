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
    
    $sigTime = !empty($sigData['signed_at']) ? \Carbon\Carbon::parse($sigData['signed_at'])->timezone($tz) : now()->timezone($tz);
    $sigDateStr = $sigTime->format('M j, Y');
    $sigTimeStr = $sigTime->format('h:i:s A');
    $timezone = $sigTime->format('e') . ' (UTC' . $sigTime->format('P') . ')';
    
    $termsVer = $sigData['terms_version'] ?? '1.0';
    $privacyVer = $sigData['privacy_version'] ?? '1.0';
    $creditVer = $sigData['credit_auth_version'] ?? '1.0';
    $sha = $sigData['sha256'] ?? 'N/A';
    // Dynamic logo resolved at top of document
    
    // Read Generated SVG-Style Icons
    // Helper: create inline SVG icon base64 with dynamic fill color
    // WHITE icons — for use on primary-colored banner background
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
    // DARK GRAY icons — for small details on white/gray backgrounds
    $icon_dark = function(string $path, string $viewBox = '0 0 512 512') {
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '"><path fill="#555555" d="' . $path . '"/></svg>'
        );
    };

    // --- ICON PATHS ---
    $p_file = 'M224 136V0H24C10.7 0 0 10.7 0 24v464c0 13.3 10.7 24 24 24h336c13.3 0 24-10.7 24-24V160H248c-13.2 0-24-10.8-24-24zm64 236c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12v8zm0-64c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12v8zm0-72v8c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-8c0-6.6 5.4-12 12-12h200c6.6 0 12 5.4 12 12zm96-114.1v6.1H256V0h6.1c6.4 0 12.5 2.5 17 7l97.9 98c4.5 4.5 7 10.6 7 16.9z';
    $p_cal = 'M0 464c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48V192H0v272zm320-196c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm-128-128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12h-40c-6.6 0-12-5.4-12-12v-40zm-128-128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-40zm0 128c0-6.6 5.4-12 12-12h40c6.6 0 12 5.4 12 12v40c0 6.6-5.4 12-12 12H76c-6.6 0-12-5.4-12-12v-40zM400 64h-48V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H160V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H48C21.5 64 0 85.5 0 112v48h448v-48c0-26.5-21.5-48-48-48z';
    $p_doc = 'M384 121.9v6.1H256V0h6.1c6.4 0 12.5 2.5 17 7l97.9 98c4.5 4.5 7 10.6 7 16.9zM248 160c13.2 0 24-10.8 24-24V0H24C10.7 0 0 10.7 0 24v464c0 13.3 10.7 24 24 24h336c13.3 0 24-10.7 24-24V160H248z';
    $p_pen = 'M290.7 93.2l128 128-278 278-114.1 12.6C12.4 513.4 0 500.9 0 486.7l12.6-114.1 278.1-279.4zm173.3-71.2l35.3 35.3c21.9 21.9 21.9 57.3 0 79.2l-35.3 35.3L336 44l35.3-35.3c21.9-21.9 57.3-21.9 79.2 0z';
    $p_check = 'M173.898 439.404l-166.4-166.4c-9.997-9.997-9.997-26.206 0-36.204l36.203-36.204c9.997-9.998 26.207-9.998 36.204 0L192 312.69 432.095 72.596c9.997-9.997 26.207-9.997 36.204 0l36.203 36.204c9.997 9.997 9.997 26.206 0 36.204l-294.4 294.401c-9.998 9.997-26.207 9.997-36.204-.001z';
    $p_monitor = 'M528 0H48C21.5 0 0 21.5 0 48v320c0 26.5 21.5 48 48 48h192l-16 48h-72c-13.3 0-24 10.7-24 24v16c0 4.4 3.6 8 8 8h272c4.4 0 8-3.6 8-8v-16c0-13.3-10.7-24-24-24h-72l-16-48h192c26.5 0 48-21.5 48-48V48c0-26.5-21.5-48-48-48zm-16 352H64V64h448v288z';
    $p_shield = 'M466.5 83.7l-192-80a48.15 48.15 0 0 0-36.9 0l-192 80C27.7 91.1 16 108.6 16 128c0 198.5 114.5 335.7 221.5 380.3 11.8 4.9 25.1 4.9 36.9 0C381.5 463.7 496 326.5 496 128c0-19.4-11.7-36.9-29.5-44.3zM256 464C161 423.5 64 304.7 64 128l192-80 192 80c0 176.7-97 295.5-192 336z';
    $p_info = 'M256 8C119 8 8 119 8 256s111 248 248 248 248-111 248-248S393 8 256 8zm0 110c22.1 0 40 17.9 40 40s-17.9 40-40 40-40-17.9-40-40 17.9-40 40-40zm40 258c0 6.6-5.4 12-12 12h-56c-6.6 0-12-5.4-12-12V240c0-6.6 5.4-12 12-12h56c6.6 0 12 5.4 12 12v168z';
    $p_badge = 'M256 0L83.2 64v102.4c0 102.4 83.2 204.8 172.8 256 89.6-51.2 172.8-153.6 172.8-256V64L256 0zm0 332.8c-44.2 0-80-35.8-80-80s35.8-80 80-80 80 35.8 80 80-35.8 80-80 80z';
    $p_lock = 'M400 224h-24v-72C376 68.2 307.8 0 224 0S72 68.2 72 152v72H48c-26.5 0-48 21.5-48 48v192c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48V272c0-26.5-21.5-48-48-48zm-104 0H152v-72c0-39.7 32.3-72 72-72s72 32.3 72 72v72z';
    $p_clock = 'M256 8C119 8 8 119 8 256s111 248 248 248 248-111 248-248S393 8 256 8zm144 276c0 6.6-5.4 12-12 12h-140c-6.6 0-12-5.4-12-12V140c0-6.6 5.4-12 12-12h32c6.6 0 12 5.4 12 12v92h96c6.6 0 12 5.4 12 12v32z';

    // Generate variables
    $fa_doc_white  = $icon_white($p_file, '0 0 384 512');
    $fa_cal_white  = $icon_white($p_cal);
    $fa_doc        = $icon_primary($p_doc, '0 0 384 512');
    $fa_users      = $icon_primary($p_pen); // using pen icon for Signature Information / user signature
    $fa_check      = $icon_white($p_check);
    $fa_check_green= 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="#00a65a" d="' . $p_check . '"/></svg>');
    $fa_monitor    = $icon_primary($p_monitor, '0 0 576 512');
    $fa_shield     = $icon_primary($p_shield);
    $fa_info       = $icon_primary($p_info);
    $fa_badge      = $icon_primary($p_badge);
    $fa_lock       = $icon_white($p_lock, '0 0 448 512');
    $fa_lock_primary = $icon_primary($p_lock, '0 0 448 512');
    $fa_cal_dark   = $icon_dark($p_cal);
    $fa_clock_dark = $icon_dark($p_clock);
    $fa_globe_dark = $icon_dark($p_lock, '0 0 448 512');

    // Dynamic, beautiful award/notary starburst seal for ESIGN & UETA compliance matching the 3rd image
    // Generate mathematically perfect starburst points to ensure symmetry and no hidden side notches
    $points = 28;
    $rOuter = 52;
    $rInner = 45;
    $cx = 60;
    $cy = 60;
    $starPath = "";
    for ($i = 0; $i < $points * 2; $i++) {
        $angle = $i * M_PI / $points;
        $r = ($i % 2 === 0) ? $rOuter : $rInner;
        $x = $cx + $r * cos($angle);
        $y = $cy + $r * sin($angle);
        $starPath .= ($i === 0 ? "M" : "L") . round($x, 2) . " " . round($y, 2);
    }
    $starPath .= "Z";

    $custom_seal = 'data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="70" height="70">' .
        '<path fill="' . $primaryColor . '" d="' . $starPath . '"/>' .
        '<circle cx="60" cy="60" r="42" fill="' . $primaryColor . '" stroke="#ffffff" stroke-width="1.5"/>' .
        '<circle cx="60" cy="60" r="37" fill="none" stroke="#ffffff" stroke-width="1" stroke-dasharray="3,2"/>' .
        '<path fill="#ffffff" d="M53 62v-5a7 7 0 0 1 14 0v5h-3v-5a4 4 0 0 0-8 0v5z"/>' .
        '<path fill="#ffffff" d="M50 62h20a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H50a2 2 0 0 1-2-2V64a2 2 0 0 1 2-2z"/>' .
        '<circle cx="60" cy="69" r="2.2" fill="' . $primaryColor . '"/>' .
        '<path fill="' . $primaryColor . '" d="M59 69h2l0.8 7h-3.6z"/>' .
        '<text x="60" y="38" fill="#ffffff" font-size="5.5" font-family="\'DejaVu Sans\', Arial, sans-serif" font-weight="bold" text-anchor="middle">ESIGN &amp; UETA</text>' .
        '<text x="60" y="90" fill="#ffffff" font-size="5.5" font-family="\'DejaVu Sans\', Arial, sans-serif" font-weight="bold" text-anchor="middle">COMPLIANT</text>' .
        '</svg>'
    );
@endphp

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
                                <div class="info-label" style="color: #ffffff; font-weight: bold; font-size: 7.5px; opacity: 0.85;">Document Reference:</div>
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
                                <div class="info-label" style="color: #ffffff; font-weight: bold; font-size: 7.5px; opacity: 0.85;">Certificate Generated:</div>
                                <div class="info-value" style="font-size: 11px; font-weight: bold; color: #ffffff;">{{ $currentTime->format('M j, Y') }} &nbsp;|&nbsp; {{ $currentTime->format('H:i:s') }} {{ $timezoneAbbr }}</div>
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

                    <!-- Sig Info -->
                    <table class="section-header">
                        <tr>
                            <td style="width: 25px;">
                                <div class="section-icon" style="background-color: #f0f5ff; width: 28px; height: 28px; border-radius: 6px; text-align: center;">
                                    <img src="{{ $fa_users }}" width="14" height="14" style="margin-top: 6px;">
                                </div>
                            </td>
                            <td class="section-title">SIGNATURE INFORMATION</td>
                        </tr>
                    </table>
                    <div class="section-line"></div>
                    
                    <div class="signature-box">
                        <table>
                            <tr>
                                <td style="width: 70%;">
                                    <div class="sig-signed-by">SIGNED BY</div>
                                    <div class="sig-name">{{ $sigName }}</div>
                                    <div class="sig-type">Typed Electronic Signature</div>
                                </td>
                                <td style="width: 30%;">
                                    <div class="status-badge">
                                        <div class="status-icon" style="background-color: transparent;">
                                            <img src="{{ $fa_check_green }}" width="20" height="20" style="margin-top: 0;">
                                        </div>
                                        <div class="status-text">STATUS</div>
                                        <div class="status-text" style="font-size: 7px; color: #00a65a; font-weight:normal;">Successfully Signed</div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <table class="data-table">
                        <tr><td class="t-label">Signature Type</td><td class="t-colon">:</td><td class="t-value">Typed Electronic Signature</td></tr>
                        <tr><td class="t-label">Signature Date</td><td class="t-colon">:</td><td class="t-value">{{ $sigDateStr }}</td></tr>
                        <tr><td class="t-label">Signature Time</td><td class="t-colon">:</td><td class="t-value">{{ $sigTimeStr }}</td></tr>
                        <tr><td class="t-label">Time Zone</td><td class="t-colon">:</td><td class="t-value">{{ $timezone }}</td></tr>
                        <tr><td class="t-label">Auth Method</td><td class="t-colon">:</td><td class="t-value">User Account Login</td></tr>
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
                        <tr><td class="t-label">SHA-256 Hash</td><td class="t-colon">:</td><td class="t-value">{{ $sha }}</td></tr>
                        <tr><td class="t-label">Cert Generation</td><td class="t-colon">:</td><td class="t-value">{{ $currentTime->format('M j, Y H:i:s') }} {{ $timezoneAbbr }}</td></tr>
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
                        <div class="final-sig-name-italic">{{ $sigName }}</div><br>
                        <div class="final-sig-bold">{{ $sigName }}</div>
                        <div class="final-sig-type">Typed Electronic Signature</div>
                        
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