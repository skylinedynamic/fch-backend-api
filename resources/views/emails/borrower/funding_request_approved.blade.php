@extends('emails.layouts.mail')

@section('title', 'Your Funding Request Has Been Approved – Complete Your Funding Application')

@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
        {{ $fundingRequest->borrower?->first_name ?? 'Valued Client' }},</h4>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        🎉 Congratulations! Your <strong>{{ $fundingRequest->funding_type }}</strong> funding request has successfully
        passed our preliminary review and has been <strong>approved</strong>.
    </p>

    <div class="box-light info-card-container"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table compact" style="width: 100%; border-collapse: collapse; table-layout: fixed; word-wrap: break-word; margin-bottom: 0;">
                <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Requested Amount:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px; word-wrap: break-word;">${{ number_format($fundingRequest->requested_amount, 2) }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Estimated Funding:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: none; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px; word-wrap: break-word;">{{ formatAmountRange($fundingRequest->fundingSequence?->amount_range) ?? ($fundingRequest->requested_amount ? '$' . number_format($fundingRequest->requested_amount, 2) : 'N/A') }}</td>
                </tr>
            </table>
        </div>
    </div>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        Your application is ready! Please keep in mind that funding cannot move forward until the Funding Application is
        completed.
    </p>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ make_url(getBorrowerDomain($fundingRequest->partner_id), 'fund-application/' . $fundingRequest->id) }}"
            class="btn"
            style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Process
            Funding Application</a>
    </div>
@endsection