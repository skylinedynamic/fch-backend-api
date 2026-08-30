@extends('emails.layouts.mail')

@section('title', 'New Funding Request Submitted')

@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $fundingRequest->borrower->first_name ?? ($fundingRequest->borrower->first_name . ' ' . $fundingRequest->borrower->last_name) }},</h4>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        A new funding request has been submitted and requires your attention. Please review the details below:
    </p>

    <div class="box-light info-card-container" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table compact" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Borrower Name:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $fundingRequest->borrower->full_name ?? ($fundingRequest->borrower->first_name . ' ' . $fundingRequest->borrower->last_name) }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Business Name:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $fundingRequest->borrower->business_name ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Funding Type:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $fundingRequest->funding_type ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Use of Funds:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $fundingRequest->use_of_funds ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Estimated Speed:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $fundingRequest->estimated_speed ?? 'N/A' }}</td>
                </tr>
                {{-- <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Matched Lenders:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $fundingRequest->lender_names ?: 'None' }}</td>
                </tr> --}}
                <tr>
                    <td class="label" style="padding: 8px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Requested Amount:</td>
                    <td class="value" style="padding: 8px 0; border-bottom: none; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">{{ currencyFormat($fundingRequest->requested_amount, 0) ?? 'N/A' }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ config('app.frontend_partner_url') }}/intake-review/funding-request/{{ $fundingRequest->id }}"
            class="btn" style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Review Application</a>
    </div>
@endsection
