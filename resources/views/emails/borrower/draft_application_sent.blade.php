@extends('emails.layouts.mail')

@section('title', 'Complete Your Funding Application')

@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
        {{ $application->first_name ?? $application->borrower?->first_name ?? 'Client' }},</h4>

    <p style="line-height: 1.5; margin-bottom: 16px;">
        Your funding application draft has been prepared by your partner. Please follow the steps below to finalize and
        submit your application:
    </p>
    <ul style="line-height: 1.6; margin-bottom: 24px; color: #495057; padding-left: 24px;">
        <li style="margin-bottom: 8px;">Carefully review all provided details to ensure your basic information is fully
            accurate.</li>
        <li style="margin-bottom: 8px;">Fill in any missing information that may still be required.</li>
        <li style="margin-bottom: 8px;">Proceed to the <strong>Attestation and authorization</strong> step.</li>
        <li>Provide your <strong>Electronic Signature (Full Legal Name) <span style="color:red;">*</span></strong> to
            officially submit the application.</li>
    </ul>

    <div class="box-light info-card-container"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table compact" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Client Name:</td>
                    <td class="value"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $application->full_name ?? $application->first_name . ' ' . $application->last_name }}
                    </td>
                </tr>
                @if ($application->business_name ?? $application->borrower?->business_name)
                    <tr>
                        <td class="label"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Company:</td>
                        <td class="value"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $application->business_name ?? $application->borrower?->business_name }}</td>
                    </tr>
                @endif
                @if ($application->partner)
                    <tr>
                        <td class="label"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Prepared By:</td>
                        <td class="value"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $application->partner->company_name ?? $application->partner->first_name . ' ' . $application->partner->last_name }}
                        </td>
                    </tr>
                @endif
                @if ($application->fundingSequence)
                    <tr>
                        <td class="label"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Funding Sequence:</td>
                        <td class="value"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $application->fundingSequence->name }}</td>
                    </tr>
                @endif
                @if ($application->fundingRequest && $application->fundingRequest->funding_type)
                    <tr>
                        <td class="label"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Funding Type:</td>
                        <td class="value"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $application->fundingRequest->funding_type }}</td>
                    </tr>
                @endif
                @if ($application->fundingRequest && $application->fundingRequest->requested_amount)
                    <tr>
                        <td class="label"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Requested Amount:</td>
                        <td class="value"
                            style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700; color: #0f172a; text-align: right; padding-right: 10px;">
                            ${{ number_format($application->fundingRequest->requested_amount, 2) }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Application ID:</td>
                    <td class="value"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        {{ $application->application_number }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 8px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Date Prepared:</td>
                    <td class="value"
                        style="padding: 8px 0; border-bottom: none; font-size: 13px; font-weight: 700; color: #495057; text-align: right; padding-right: 10px;">
                        {{ \Carbon\Carbon::now()->format('F j, Y') }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ $applicationUrl }}" class="btn"
            style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Review
            & Complete Application</a>
    </div>
@endsection