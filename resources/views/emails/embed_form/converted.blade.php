@extends('emails.layouts.mail')

@section('title', $forStaff ? 'Applicant converted to a client' : 'Your funding application is in review')

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        @if($forStaff)
            <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello,</h4>
            <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
                An embedded form applicant has been converted to a client. Their funding application has
                carried over in full and is ready for review &mdash; nothing needs to be re-entered.
            </p>
        @else
            <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">
                Hello {{ $submission->first_name }},
            </h4>
            <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
                Thank you for your application. Your client account with <strong>{{ $siteName }}</strong>
                is now open and your application is with our team &mdash; there is nothing further you need to
                send us, and no need to apply again.
            </p>
        @endif

        <div class="box-light info-card-container"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 4px; margin-bottom: 24px; text-align: left; overflow: hidden;">
            <div class="info-card-header"
                style="background-color: #f0f7ff; padding: 16px 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 15px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
                Funding Application
            </div>
            <div style="padding: 20px;">
                <table style="width: 100%; border-collapse: collapse;">
                    @if($application->application_number)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; width: 45%; padding-left: 10px;">Application</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $application->application_number }}</td>
                    </tr>
                    @endif
                    @if($forStaff)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Applicant</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $submission->first_name }} {{ $submission->last_name }}</td>
                    </tr>
                    @endif
                    @if($submission->business_name)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Business</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; text-align: right; padding-right: 10px;">{{ $submission->business_name }}</td>
                    </tr>
                    @endif
                    @if($application->requested_amount)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Requested Amount</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0e9f6e; text-align: right; padding-right: 10px;">{{ $application->currency_symbol ?: '$' }}{{ number_format($application->requested_amount, 2) }}</td>
                    </tr>
                    @endif
                    @if($application->preferred_funding_type)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Funding Type</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; text-align: right; padding-right: 10px;">{{ $application->preferred_funding_type }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding: 9px 0; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Status</td>
                        <td style="padding: 9px 0; font-size: 13px; font-weight: 600; color: #f59e0b; text-align: right; padding-right: 10px;">{{ $application->status }}</td>
                    </tr>
                </table>
            </div>
        </div>

        @if($loginUrl)
            <div style="text-align: center; margin-bottom: 24px;">
                <a href="{{ make_url($loginUrl) }}"
                   style="display: inline-block; background: #1e3a8a; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 4px; font-size: 14px; font-weight: 600;">
                    Sign in to your account
                </a>
            </div>
        @endif

        <div style="background: #f0f7ff; border-radius: 4px; padding: 14px 16px; font-size: 13px; color: #1e3a8a; margin-bottom: 20px;">
            📋 <strong>Reference ID:</strong> {{ $submission->submission_uid }}
        </div>

        <p style="line-height: 1.5; color: #495057; font-size: 13px;">
            @if($forStaff)
                Open the client record in your portal to review the application and take the next step.
            @else
                We will be in touch as soon as we have an update. If anything else is needed, we will contact you directly.
            @endif
        </p>
    </div>
@endsection
