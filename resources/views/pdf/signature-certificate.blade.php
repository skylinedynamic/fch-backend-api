<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Electronic Signature Certificate</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #333; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .title { font-size: 24px; font-weight: bold; margin: 0; }
        .subtitle { font-size: 14px; color: #666; margin: 5px 0 0 0; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 18px; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #eee; }
        th { width: 40%; color: #555; }
        .footer { margin-top: 40px; font-size: 12px; color: #888; text-align: center; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h1 class="title">Electronic Signature Certificate</h1>
        <p class="subtitle">Document Ref: {{ $application->application_number }}</p>
    </div>

    <div class="section">
        <h2 class="section-title">Electronic Signature Certificate Fields</h2>
        <table>
            <tr><th>Application ID</th><td>{{ $application->application_number }}</td></tr>
            <tr><th>Borrower Name</th><td>{{ $application->first_name }} {{ $application->last_name }}</td></tr>
            <tr><th>Business Name</th><td>{{ $application->business_name }}</td></tr>
            <tr><th>Electronic Signature (typed name)</th><td><strong>{{ $signatureData['signature_name'] ?? '' }}</strong></td></tr>
            <tr><th>Date/Time/Timezone</th><td>{{ $signatureData['signed_at'] ?? '' }} {{ $signatureData['timezone'] ?? 'UTC' }}</td></tr>
            <tr><th>Public IP Address</th><td>{{ $signatureData['ip_address'] ?? '' }}</td></tr>
            <tr><th>Browser & Version</th><td>{{ $signatureData['browser_name'] ?? 'Unknown' }}</td></tr>
            <tr><th>Operating System</th><td>{{ $signatureData['operating_system'] ?? 'Unknown' }}</td></tr>
            <tr><th>Device Type</th><td>{{ preg_match('/mobile|android|iphone|ipad/i', $signatureData['user_agent'] ?? '') ? 'Mobile' : 'Desktop' }}</td></tr>
            <tr><th>Terms Version</th><td>1.0</td></tr>
            <tr><th>Privacy Policy Version</th><td>1.0</td></tr>
            <tr><th>Credit Authorization Version</th><td>1.0</td></tr>
            <tr><th>Authentication Method</th><td>User Account Login</td></tr>
            <tr><th>ESIGN Consent Status</th><td>{{ !empty($signatureData['esign_consent']) ? 'Accepted' : 'Accepted' }}</td></tr>
            <tr><th>Credit Authorization Status</th><td>{{ !empty($signatureData['credit_consent']) ? 'Accepted' : 'Accepted' }}</td></tr>
            <tr><th>SHA-256 Hash (optional)</th><td>N/A</td></tr>
            <tr><th>Certificate Generation Timestamp</th><td>{{ now()->format('Y-m-d H:i:s') }} UTC</td></tr>
        </table>
    </div>

    <div class="section">
        <h2 class="section-title">Detailed Consents & Agreements</h2>
        <table>
            @if(!empty($signatureData['consents_data']) && is_array($signatureData['consents_data']))
                @foreach($signatureData['consents_data'] as $consent)
                    <tr>
                        <th style="width: 70%;">{{ $consent['label'] ?? '' }}</th>
                        <td style="width: 30%;">{{ !empty($consent['checked']) ? 'Accepted' : 'Not Accepted' }}</td>
                    </tr>
                @endforeach
            @endif
        </table>
    </div>

    <div class="footer">
        This document serves as proof of electronic signature under the ESIGN Act, UETA, and applicable laws.<br>
        Generated at: {{ now()->format('Y-m-d H:i:s') }} UTC
    </div>
</body>
</html>
