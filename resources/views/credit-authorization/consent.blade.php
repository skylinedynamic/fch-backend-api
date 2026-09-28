{{--
    The artefact sent to iSoftPull when they ask us to prove a client authorised
    a credit pull.

    Written to be read by someone who was not there: it quotes the wording as the
    client saw it, names the version and where that version is published, and
    states the time, address and device the agreement came from. Nothing here is
    generated from today's configuration - every value comes off the stored
    record, which is why a later change to the published wording cannot alter
    what this document says.

    No SSN, and no credit data. This proves permission was given; it is not the
    report.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Credit Authorization Record — {{ $borrowerName }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .sub { color: #555; font-size: 10px; margin-bottom: 14px; }
        .rule { border-top: 2px solid #1a1a1a; margin: 0 0 14px; }
        h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #ccc; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 4px 0; vertical-align: top; }
        td.k { width: 170px; color: #555; }
        td.v { font-weight: bold; }
        .quote { border: 1px solid #ccc; background: #fafafa; padding: 9px 11px; margin: 6px 0 4px; }
        .tick { font-weight: bold; }
        .cite { font-size: 10px; color: #555; margin-top: 3px; }
        .foot { margin-top: 20px; padding-top: 8px; border-top: 1px solid #ccc; font-size: 9px; color: #666; }
    </style>
</head>
<body>

<h1>Credit Authorization Record</h1>
<div class="sub">
    Evidence of the consumer's written instructions under the Fair Credit Reporting Act.
</div>
<div class="rule"></div>

<h2>Consumer</h2>
<table>
    <tr><td class="k">Name</td><td class="v">{{ $borrowerName }}</td></tr>
    <tr><td class="k">Client ID</td><td class="v">{{ $consent->borrower_id }}</td></tr>
    @if($borrowerEmail)
        <tr><td class="k">Email</td><td class="v">{{ $borrowerEmail }}</td></tr>
    @endif
</table>

<h2>Agreement</h2>
<table>
    <tr>
        <td class="k">Agreed at (UTC)</td>
        <td class="v">{{ optional($consent->consented_at)->format('Y-m-d H:i:s') }} UTC</td>
    </tr>
    <tr><td class="k">IP address</td><td class="v">{{ $consent->ip_address ?: '—' }}</td></tr>
    <tr><td class="k">Browser / user agent</td><td class="v">{{ $consent->user_agent ?: '—' }}</td></tr>
    <tr><td class="k">Authorization version</td><td class="v">{{ $consent->authorization_version }}</td></tr>
</table>

<h2>1. Credit Authorization <span class="tick">[{{ $consent->credit_authorization_accepted ? 'X' : ' ' }}] Agreed</span></h2>
<div class="quote">{{ $consent->credit_authorization_text }}</div>
{{-- The published copy, so the wording can be verified at source rather than
     taken from this document. --}}
<div class="cite">{{ $consent->citation() }}</div>

<h2>2. Electronic Communications Consent <span class="tick">[{{ $consent->e_consent_accepted ? 'X' : ' ' }}] Agreed</span></h2>
<div class="quote">{{ $consent->e_consent_text }}</div>

<h2>Credit Pull</h2>
<table>
    <tr>
        <td class="k">iSoftPull transaction ID</td>
        <td class="v">{{ $consent->isoftpull_transaction_id ?: 'Not recorded' }}</td>
    </tr>
    <tr>
        <td class="k">Pulled at (UTC)</td>
        <td class="v">
            {{ $consent->pulled_at ? $consent->pulled_at->format('Y-m-d H:i:s') . ' UTC' : 'Not yet pulled' }}
        </td>
    </tr>
</table>

<div class="foot">
    Generated {{ $generatedAt }} UTC · Record #{{ $consent->id }}<br>
    This document evidences the consumer's authorization only. It contains no credit report data.
</div>

</body>
</html>
