@extends('emails.layouts.mail')

@section('title', 'Account Security Update - ' . $business_info['company_name'])

@section('content')

    <div class="description-card">
        <h4>Hello <strong>{{ $user->name }}</strong>,</h4>

        <p class="mb-5 text-muted">
            Your partner account credentials on <strong>{{ $business_info['company_name'] }}</strong> have been successfully
            updated. For security purposes, a new temporary password has been generated for your convenience.
        </p>

        {{-- Update Summary --}}
        <div class="box-light">
            <p class="section-label mb-3">Security Update Details</p>
            <ul>
                <li><strong>Account Access:</strong> Your primary login email has been updated.</li>
                <li><strong>Credentials:</strong> A unique temporary password is now active.</li>
                <li><strong>Next Action:</strong> You will be required to update your password upon your next successful
                    login.</li>
            </ul>
        </div>

        {{-- Login Credentials --}}
        <div class="section-label mb-2">New Login Credentials</div>
        <table class="detail-table">
            <tr>
                <td class="label">Updated Email</td>
                <td class="value">{{ $user->email }}</td>
            </tr>
            @if (!empty($plainPassword))
                <tr>
                    <td class="label">Temporary Password</td>
                    <td class="value"><span class="temp-password">{{ $plainPassword }}</span></td>
                </tr>
            @endif
        </table>

        {{-- Action Button --}}
        <div class="box-primary">
            <p class="mb-4">Please log in to your partner portal using your updated credentials to confirm the changes.
            </p>
            <a href="{{ config('app.frontend_partner_url') }}/login" class="btn">Access Partner Portal</a>
        </div>

        {{-- Security Notice --}}
        <div class="box-note">
            <p class="section-label mb-2">Important Security Notice</p>
            <p class="text-muted mb-0 small" style="line-height: 1.6;">
                If you did not initiate this change, please contact our security team immediately at
                <a href="mailto:{{ $business_info['email'] }}"
                    style="color: #1e3a8a; font-weight: 600;">{{ $business_info['email'] }}</a>.
                Securing your account is our top priority.
            </p>
        </div>

        <p class="text-muted mt-5 mb-0">
            Regards,<br>
            <strong>{{ config('app.name') }}</strong><br>
            Partner Operations Team
        </p>
    </div>
@endsection
