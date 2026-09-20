@extends('emails.layouts.mail')

@section('title', 'New Loan Application — ' . $submission->first_name . ' ' . $submission->last_name)

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello,</h4>

        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            A new loan application has been submitted through your embedded form. Please review the applicant details below.
        </p>

        {{-- Applicant Details --}}
        <div class="box-light info-card-container"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 4px; margin-bottom: 24px; text-align: left; overflow: hidden;">
            <div class="info-card-header"
                style="background-color: #f0f7ff; padding: 16px 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 15px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
                Applicant Information
            </div>
            <div style="padding: 20px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; width: 40%; padding-left: 10px;">Full Name</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $submission->first_name }} {{ $submission->last_name }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; width: 40%; padding-left: 10px;">Email</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; text-align: right; padding-right: 10px;">{{ $submission->email }}</td>
                    </tr>
                    @if($submission->phone)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Phone</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; text-align: right; padding-right: 10px;">{{ $submission->phone }}</td>
                    </tr>
                    @endif
                    @if($submission->credit_score)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Credit Score</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #1e3a8a; text-align: right; padding-right: 10px;">{{ $submission->credit_score }}</td>
                    </tr>
                    @endif
                    @if($submission->desired_funding_amount)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Desired Amount</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0e9f6e; text-align: right; padding-right: 10px;">${{ number_format($submission->desired_funding_amount, 2) }}</td>
                    </tr>
                    @endif
                    @if($submission->employer_name)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Employer</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; text-align: right; padding-right: 10px;">{{ $submission->employer_name }}</td>
                    </tr>
                    @endif
                    @if($submission->annual_income)
                    <tr>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Annual Income</td>
                        <td style="padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; text-align: right; padding-right: 10px;">${{ number_format($submission->annual_income, 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding: 9px 0; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-left: 10px;">Documents</td>
                        <td style="padding: 9px 0; font-size: 13px; font-weight: 600; color: #f59e0b; text-align: right; padding-right: 10px;">{{ $submission->attachments->count() }} file(s) uploaded</td>
                    </tr>
                </table>
            </div>
        </div>

        <div style="background: #f0f7ff; border-radius: 4px; padding: 14px 16px; font-size: 13px; color: #1e3a8a; margin-bottom: 20px;">
            📋 <strong>Reference ID:</strong> {{ $submission->submission_uid }}<br>
            🕐 <strong>Submitted:</strong> {{ $submission->created_at?->format('F j, Y \a\t g:i A') }}
        </div>

        <p style="line-height: 1.5; color: #495057; font-size: 13px;">
            Log in to your portal to view the full details, download documents, and update the application status.
        </p>
    </div>
@endsection
