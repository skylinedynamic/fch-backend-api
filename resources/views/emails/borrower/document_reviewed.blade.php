@extends('emails.layouts.mail')

@section('title', $siteName . ' - Document ' . $status)

@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Dear {{ $borrowerName }},</h4>

    <p>
        Your document <strong>{{ $documentName }}</strong> has been reviewed and was <strong>{{ $status }}</strong>.
    </p>

    @if ($reason)
        <div style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin: 16px 0;">
            <p style="margin: 0 0 4px 0; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #b91c1c; letter-spacing: 0.5px;">Reason</p>
            <p style="margin: 0; color: #0f172a;">{{ $reason }}</p>
        </div>

        <p>Please upload a new file for this document from your Documents page.</p>
    @endif

    {{-- Only when the client's partner has a client portal (R3-BUG-013). --}}
    @if (!empty($documentsUrl))
        <p style="text-align: center; margin: 24px 0;">
            <a href="{{ $documentsUrl }}"
                style="display: inline-block; background-color: #1e3a8a; color: #ffffff; text-decoration: none; padding: 10px 24px; border-radius: 6px; font-weight: 600;">
                Go to My Documents
            </a>
        </p>
    @endif
@endsection
