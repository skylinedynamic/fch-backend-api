@extends('emails.layouts.mail')

@section('title', 'New Demo Request from ' . $demoRequest->customer_name)

@section('content')
    <div class="appointment-title mt-3" style="font-size: 20px; font-weight: 700; color: #1a2e44; margin-bottom: 24px; margin-top: 16px;">New Demo Request Received</div>

    <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $assignedUser->name ?? 'Team' }},</h4>
    <p style="line-height: 1.5; margin-bottom: 16px;">
        A new demo request has been submitted by <strong>{{ $demoRequest->customer_name }}</strong>.
        Below are the request details:
    </p>

    <div class="info-card-container box-light" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header" style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Request Details
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Customer Name:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $demoRequest->customer_name }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Full Name:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $demoRequest->full_name }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Phone:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $demoRequest->phone }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Email:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $demoRequest->email }}</td>
                </tr>
                @if (!empty($demoRequest->country))
                    <tr>
                        <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Country:</td>
                        <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $demoRequest->country }}</td>
                    </tr>
                @endif
                @if (!empty($demoRequest->website))
                    <tr>
                        <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Website:</td>
                        <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #1e3a8a; text-align: right; padding-right: 10px;"><a href="{{ $demoRequest->website }}"
                                target="_blank" style="color: #1e3a8a; text-decoration: underline;">{{ $demoRequest->website }}</a></td>
                    </tr>
                @endif
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Demo Start Date:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $demoRequest->demo_start ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Demo End Date:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $demoRequest->demo_end ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Status:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;"><strong style="text-transform:capitalize;">{{ $demoRequest->status }}</strong></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ url('/') }}" class="btn" style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View in Dashboard</a>
    </div>
@endsection
