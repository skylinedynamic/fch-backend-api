<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Electronic Signature Certificate</title>
@include('pdf.partials.styles')
<style>
    .rows td { padding: 5px 8px; border-bottom: 1px solid #eceef1; }
    .rows .status { width: 16%; text-align: right; }
    .rows .version { width: 12%; }
    .rows .head td { color: #6b7280; }
    .signature-box { border: 1px solid #d1d5db; padding: 12px 14px; }
    .signature-name { font-size: 12px; color: #111827; padding-bottom: 4px; border-bottom: 1px solid #d1d5db; margin-bottom: 8px; }
    .signature-box td { padding: 2px 0; }
</style>
</head>
<body>
@php
    $title = 'Electronic Signature Certificate';

    // Typed name or e-signature (drawn / uploaded image).
    $isEsign = is_array($sigData ?? null) && ($sigData['signature_type'] ?? 'typed') === 'esign';
    $sigImage = $isEsign ? ($sigData['signature_image'] ?? null) : null;
    $statusClass = fn ($status) => $status === 'Accepted' ? 'accepted' : 'muted';
@endphp

@include('pdf.partials.footer')
@include('pdf.partials.header')

@include('pdf.partials.fields', ['title' => 'Certificate Information', 'wide' => ['Signed Copy SHA-256'], 'fields' => [
    'Certificate ID' => $certId,
    'Application ID' => $appId,
    'Application Version' => $appVersion,
    'Status at Signing' => $status,
    'Signed Copy SHA-256' => $sha,
]])

@include('pdf.partials.fields', ['title' => 'Borrower & Signer', 'wide' => ['Authentication'], 'fields' => [
    'Borrower' => $borrowerName,
    'Business Name' => $businessName,
    'Name Signed' => $signedName,
    'Signature Type' => $sigImage ? 'Drawn / uploaded' : 'Typed',
    'Signing Method' => $signingMethod,
    'Signer Account' => $signerAccount,
    'Authentication' => $authentication,
]])

@include('pdf.partials.fields', ['title' => 'Signing Time & Device', 'wide' => ['Signed At (Signer)', 'Signed At (UTC)'], 'fields' => [
    'Signed At (Signer)' => $signedAtLocal ?? "Not recorded - the signer's timezone was not captured",
    'Signed At (UTC)' => $signedAtUtc,
    'Signer Timezone' => $signerTimezone ?? 'Not recorded',
    'IP Address' => $ip,
    'Browser' => $browser,
    'Operating System' => $os,
    'Device Type' => $device,
]])

<div class="section">
    <div class="section-title">Document &amp; Policy Versions</div>
    <table class="rows">
        @if($policies)
            <tr class="head"><td>Document</td><td class="version">Version</td><td class="status">Status</td></tr>
            @foreach($policies as $policy)
                <tr>
                    <td>{{ $policy['label'] }}@if($policy['url'])<br><span class="muted">{{ $policy['url'] }}</span>@endif</td>
                    <td class="version">{{ $policy['version'] }}</td>
                    <td class="status {{ $statusClass($policy['status']) }}">{{ $policy['status'] }}</td>
                </tr>
            @endforeach
        @else
            <tr><td class="muted">Not recorded - this signature was taken before the policy versions were captured.</td></tr>
        @endif
    </table>
</div>

<div class="section">
    <div class="section-title">Consents &amp; Agreements</div>
    <table class="rows">
        <tr>
            <td>I consent to sign electronically and that {{ $sigImage ? 'the signature I provided' : 'typing my name' }} is my legally binding electronic signature under the ESIGN Act and applicable state laws.</td>
            <td class="status {{ $statusClass($consents['esign']) }}">{{ $consents['esign'] }}</td>
        </tr>
        <tr>
            <td>I have read and agree to the Terms &amp; Conditions.</td>
            <td class="status {{ $statusClass($consents['terms']) }}">{{ $consents['terms'] }}</td>
        </tr>
        <tr>
            <td>I authorize {{ $companyName }} to obtain and review my business and/or personal credit information as described.</td>
            <td class="status {{ $statusClass($consents['credit']) }}">{{ $consents['credit'] }}</td>
        </tr>
        <tr>
            <td>I agree that my electronic signature has the same legal effect as a handwritten signature.</td>
            <td class="status {{ $statusClass($consents['legal']) }}">{{ $consents['legal'] }}</td>
        </tr>
    </table>
</div>

<div class="section">
    <div class="section-title">Signature</div>
    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 56%; padding-right: 20px;" class="note">
                {{ $sigImage ? 'By providing my electronic signature' : 'By typing my full legal name' }} and clicking
                &ldquo;Submit Application,&rdquo; I certify that all information provided is true and accurate,
                consent to receive records electronically, authorize {{ $companyName }} and its funding partners
                to obtain and review my business and/or personal credit information as permitted by law, and agree
                that my electronic signature has the same legal effect as a handwritten signature under the ESIGN
                Act, UETA, and other applicable law.
            </td>
            <td style="width: 44%;">
                <div class="signature-box">
                    @if($sigImage)
                        <div class="signature-name"><img src="{{ $sigImage }}" style="max-width: 100%; max-height: 50px;"></div>
                    @else
                        <div class="signature-name">{{ $signedName }}</div>
                    @endif
                    <table>
                        <tr><td class="muted" style="width: 42%;">Name signed</td><td>{{ $signedName }}</td></tr>
                        <tr><td class="muted">Signing method</td><td>{{ $signingMethod }}</td></tr>
                        @if($sigImage && !empty($sigData['signature_date']))
                            {{-- The date the signer wrote in the signature block. --}}
                            <tr><td class="muted">Date written</td><td>{{ toDateOnly($sigData['signature_date'], 'M j, Y') }}</td></tr>
                        @endif
                        <tr><td class="muted">Signed on</td><td>{{ $signedOn }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="section note">
    This certificate is proof of electronic signature under the ESIGN Act, UETA, and applicable federal and
    state laws. It certifies {{ $appVersion === 'Not recorded' ? 'the application' : strtolower($appVersion) . ' of the application' }}
    as signed; the signed copy is kept unchanged and is identified by the SHA-256 fingerprint above.
</div>

</body>
</html>
