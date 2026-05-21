@extends('emails.layouts.mail')

@section('title', 'Account Security Update')

@section('content')

    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $user->name }}</strong>,</h4>

        <p style="line-height: 1.5; margin-bottom: 32px; color: #64748b;">
            Your partner account credentials on <strong>{{ $business_info['company_name'] }}</strong> have been successfully
            updated. For security purposes, a new temporary password has been generated for your convenience.
        </p>

        {{-- Update Summary --}}
        <div class="box-light"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
            <p class="section-label mb-3"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">
                Security Update Details</p>
            <ul style="margin: 0; padding-left: 20px; color: #495057; line-height: 1.6; font-size: 14px;">
                <li><strong>Account Access:</strong> Your primary login email has been updated.</li>
                <li><strong>Credentials:</strong> A unique temporary password is now active.</li>
                <li><strong>Next Action:</strong> You will be required to update your password upon your next successful
                    login.</li>
            </ul>
        </div>

        {{-- Login Credentials --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            New Login Credentials</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Updated Email</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #1e3a8a; text-align: right; padding-right: 10px;">
                    {{ $user->email }}</td>
            </tr>
            @if (!empty($plainPassword))
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Temporary Password</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        <span class="temp-password"
                            style="background: #ffffff; padding: 6px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-family: 'Courier New', Courier, monospace; font-weight: 600; color: #1e3a8a; display: inline-block; letter-spacing: 1px;">{{ $plainPassword }}</span>
                    </td>
                </tr>
            @endif
        </table>

        {{-- Action Button --}}
        <div class="box-primary"
            style="background-color: #f0f7ff; border: 1px solid #e0effe; border-radius: 8px; padding: 24px; margin-bottom: 24px; text-align: center;">
            <p style="line-height: 1.5; margin-bottom: 24px;">Please log in to your partner portal using your updated
                credentials to confirm the changes.
            </p>
            <a href="{{ config('app.frontend_partner_url') }}/login" class="btn"
                style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Access
                Partner Portal</a>
        </div>

        {{-- Security Notice --}}
        <div class="box-note"
            style="background-color: #f8fafc; border-left: 4px solid #1e3a8a; border-radius: 4px; padding: 16px 20px; margin-top: 32px; margin-bottom: 24px;">
            <p class="section-label mb-2"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                Important Security Notice</p>
            <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin: 0;">
                If you did not initiate this change, please contact our security team immediately at
                <a href="mailto:{{ $business_info['email'] }}"
                    style="color: #1e3a8a; font-weight: 600; text-decoration: none;">{{ $business_info['email'] }}</a>.
                Securing your account is our top priority.
            </p>
        </div>

        <p style="color: #64748b; line-height: 1.5; margin-top: 32px; margin-bottom: 0;">
            Regards,<br>
            <strong style="color: #495057;">{{ config('app.name') }}</strong><br>
            Partner Operations Team
        </p>
    </div>
@endsection
