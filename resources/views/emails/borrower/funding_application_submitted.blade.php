@extends('emails.layouts.mail')

@section('title', 'Funding Application Submitted - ' . ($siteName ?? config('app.name')))

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $application->borrower?->first_name ?? 'Client' }},</h4>
            <p style="color: #475569; line-height: 1.6; margin-bottom: 20px; font-size: 15px;">
                Your funding application has been successfully submitted and is now under review. We will notify you once there is an update.
            </p>

            <table class="info-table"
                style="width: 100%; border-collapse: collapse; background-color: #f8fafc; border-radius: 6px; overflow: hidden; margin-bottom: 25px;">
                <tr>
                    <td class="label" style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%;">Application ID:</td>
                    <td class="value" style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-size: 14px; font-weight: 600; color: #1e3a8a; text-align: right;">{{ $application->application_number }}</td>
                </tr>
            </table>
    </div>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ $applicationUrl }}"
            class="btn" style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View Application</a>
    </div>
@endsection
