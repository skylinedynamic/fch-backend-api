<?php

namespace App\Services\FCH\Common\Application;

use Carbon\Carbon;
use App\Models\Borrower\FundingApplication;

class SignatureCertificateService
{
    /**
     * Get all pre-calculated variables and SVG icons required for the signature certificate PDF.
     *
     * @param FundingApplication $application
     * @param array $brandingData
     * @param array|null $signatureData
     * @return array
     */
    public static function getTemplateData($application, $brandingData, $signatureData = null)
    {
        $tz = $brandingData['tz'] ?? 'UTC';
        $primaryColor = $brandingData['primaryColor'] ?? '#0056b3';

        $rawSigData = $signatureData ?? $application->signature_data ?? [];
        $sigData = is_string($rawSigData) ? json_decode($rawSigData, true) : $rawSigData;
        if (!is_array($sigData)) $sigData = [];
        
        $appId = $application->application_number ?? ('FUND-'.str_pad($application->id, 6, '0', STR_PAD_LEFT));
        $borrowerName = trim(($application->first_name ?? '') . ' ' . ($application->last_name ?? ''));
        $businessName = $application->business_name ?? 'N/A';
        $rawStatus = $application->status ?? FundingApplication::PENDING;
        $status = FundingApplication::STATUS[$rawStatus] ?? ucfirst(str_replace('_', ' ', $rawStatus));
        
        $ip = $sigData['ip_address'] ?? '127.0.0.1';
        $browser = $sigData['browser_name'] ?? 'Chrome';
        $os = $sigData['operating_system'] ?? 'Windows';
        $device = $sigData['device_type'] ?? 'Desktop';
        
        $sigName = $sigData['signature_name'] ?? $borrowerName;
        if (empty($sigName)) $sigName = 'N/A';
        
        $sigTime = !empty($sigData['signed_at']) ? Carbon::parse($sigData['signed_at'])->timezone($tz) : now()->timezone($tz);
        $sigDateStr = $sigTime->format('M j, Y');
        $sigTimeStr = $sigTime->format('h:i:s A');
        $timezone = $sigTime->format('e') . ' (UTC' . $sigTime->format('P') . ')';
        
        $formatConsent = function($versionKey, $consentKey) use ($sigData) {
            // Check for explicit boolean consent first
            if (isset($sigData[$consentKey])) {
                return $sigData[$consentKey] ? 'Agreed' : 'Not Agreed';
            }
            // Fallback to older versioning logic if passed as 1 or 0
            if (isset($sigData[$versionKey])) {
                return (in_array($sigData[$versionKey], ['1', 1, '1.0', true], true)) ? 'Checked' : 'Unchecked';
            }
            return 'Agreed';
        };

        $termsVer = $formatConsent('terms_version', 'terms_consent');
        $privacyVer = $formatConsent('privacy_version', 'terms_consent'); // Assuming privacy is bundled with terms
        $creditVer = $formatConsent('credit_auth_version', 'credit_consent');
        $sha = $sigData['sha256'] ?? 'N/A';
        $certId = $sigData['certificate_id'] ?? ($brandingData['certId'] ?? 'N/A');
        $appVersion = $sigData['application_version'] ?? 'N/A';
        
        // Helper: create inline SVG icon base64 with dynamic fill color
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
        $fa_users      = $icon_primary($p_pen); 
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

        return compact(
            'application', 'sigData', 'appId', 'borrowerName', 'businessName', 'rawStatus', 'status',
            'ip', 'browser', 'os', 'device', 'sigName', 'sigDateStr', 'sigTimeStr', 'timezone',
            'termsVer', 'privacyVer', 'creditVer', 'sha', 'certId', 'appVersion',
            'fa_doc_white', 'fa_cal_white', 'fa_doc', 'fa_users', 'fa_check', 'fa_check_green',
            'fa_monitor', 'fa_shield', 'fa_info', 'fa_badge', 'fa_lock', 'fa_lock_primary',
            'fa_cal_dark', 'fa_clock_dark', 'fa_globe_dark', 'custom_seal'
        );
    }
}
