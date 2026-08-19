@extends('emails.layouts.master')

@section('content')
    <div class="content"
        style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); overflow: hidden; margin-bottom: 20px;">
        <div class="content-header"
            style="background-color: #f8fafc; padding: 25px 30px; border-bottom: 1px solid #e2e8f0; text-align: center;">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $siteName }}" class="logo"
                    style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
            @else
                <h1
                    style="margin: 0; color: #1e293b; font-size: 24px; font-weight: 700; letter-spacing: -0.5px; text-align: center;">
                    {{ $siteName }}</h1>
            @endif
        </div>
        <div class="content-body" style="padding: 30px;">
            <h2 style="margin-top: 0; color: #0f172a; font-size: 20px; font-weight: 600; margin-bottom: 20px;">Hello {{ $application->borrower?->first_name ?? 'Client' }},</h2>
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
    </div>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ $applicationUrl }}"
            class="btn" style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View Application</a>
    </div>
@endsection