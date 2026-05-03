@extends('emails.layouts.mail')

@section('title', 'Cron Job Failure')

@section('content')
    <h2 style="color: #ef4444; font-size: 20px; font-weight: 700; margin: 0 0 16px 0;">⚠️ Cron Job Failure Notification</h2>
    <p style="line-height: 1.5; margin-bottom: 24px;">A scheduled task has failed on <strong>{{ config('app.name') }}</strong>.</p>

    <div class="box-light info-card-container" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header" style="background-color: #fef2f2; padding: 20px; text-align: center; border-bottom: 1px solid #fee2e2; font-size: 16px; font-weight: 700; color: #ef4444; text-transform: uppercase; letter-spacing: 0.5px;">
            Job Details
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Job Name:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $data['job_name'] }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Error Message:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #ef4444; text-align: right; padding-right: 10px;">{{ $data['error_message'] }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Timestamp:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $data['timestamp'] }}</td>
                </tr>
                @if(!empty($data['user_email']))
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">User Email:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $data['user_email'] }}</td>
                </tr>
                @endif
                @if(!empty($data['user_id']))
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">User ID:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $data['user_id'] }}</td>
                </tr>
                @endif
                @if(!empty($data['partner_name']))
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Partner Name:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $data['partner_name'] }}</td>
                </tr>
                @endif
                @if(isset($data['user_context']))
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">User Context:</td>
                    <td class="value" style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $data['user_context'] }}</td>
                </tr>
                @endif
            </table>
        </div>
    </div>

    <div class="box-note" style="background-color: #f8fafc; border-left: 4px solid #ef4444; border-radius: 4px; padding: 16px 20px; margin-top: 32px; margin-bottom: 24px;">
        <strong style="color: #ef4444;">Stack Trace Preview:</strong>
        <pre style="white-space: pre-wrap; word-wrap: break-word; font-size: 12px; margin-top: 10px; color: #495057; font-family: 'Courier New', Courier, monospace;">{{ Str::limit($data['stack_trace'], 500) }}</pre>
    </div>

    <p style="font-size: 12px; color: #878a99; margin-top: 30px;">
        This is an automated notification from the server.
    </p>
@endsection