@extends('emails.layouts.mail')

@section('title', 'Welcome to ' . $business_info['company_name'])

@section('content')

    <div class="description-card">
        <p class="mb-3">
            Hello <strong>{{ $user->name }}</strong>,
        </p>

        <p class="mb-3">
            Welcome to <strong>{{ $business_info['company_name'] }}</strong>.
        </p>

        <p class="mb-5 text-muted">
            Your partner account has been successfully created, and you now have access to the
            <strong>{{ $business_info['company_name'] }} Partner Portal</strong>.
        </p>

        {{-- Next Steps --}}
        <div class="box-light">
            <p class="section-label mb-3">What you can do next:</p>
            <ul>
                <li>Log in to your Partner Dashboard</li>
                <li>Add and manage clients</li>
                <li>Track client progress through tiers and underwriting</li>
                <li>View commission activity (as applicable)</li>
                <li>Access partner training and resources</li>
            </ul>
        </div>

        @php
            $displayAmount = (float) ($amount ?? 0);
        @endphp

        {{-- Enrollment Overview --}}
        <div class="section-label mb-2">Enrollment Overview</div>
        <table class="detail-table">
            <tr>
                <td class="label">Partner Name</td>
                <td class="value">{{ $user->name }}</td>
            </tr>
            <tr>
                <td class="label">Enrollment Type</td>
                <td class="value">{{ $enrollmentType }}</td>
            </tr>
            @if ($displayAmount > 0)
                <tr>
                    <td class="label">Payment Amount</td>
                    <td class="value">${{ number_format($displayAmount, 2) }}</td>
                </tr>
            @endif
            @if (!empty($plainPassword))
                <tr>
                    <td class="label">Temporary Password</td>
                    <td class="value"><span class="temp-password">{{ $plainPassword }}</span></td>
                </tr>
            @endif
        </table>

        {{-- Action Section --}}
        @if ($displayAmount > 0)
            <div class="box-primary">
                <p class="mb-4">To finalize your enrollment and activate your dashboard access, please complete the secure
                    payment process.</p>
                <a href="{{ $paymentLink }}" class="btn">Complete Enrollment Payment</a>
                <p class="text-muted small mt-4 mb-0">Total Amount Due:
                    <strong>${{ number_format($displayAmount, 2) }}</strong>
                </p>
            </div>
        @else
            <div class="box-primary">
                <p class="section-label mb-3">Partner Portal Login:</p>
                <a href="{{ config('app.frontend_partner_url') }}/login" class="btn">Go to Partner Dashboard</a>
                <p class="text-muted small mt-4 mb-0">
                    For security reasons, if this is your first login, you may be prompted to set or confirm your password.
                </p>
            </div>
        @endif

        {{-- Note / Disclaimer --}}
        <div class="box-note">
            <p class="section-label mb-2">Please note:</p>
            <p class="text-muted mb-3 small" style="line-height: 1.6;">
                Partner access is role-based and read-only for underwriting decisions. All underwriting, tier advancement,
                and funding decisions remain managed by the <strong>{{ $business_info['company_name'] }}</strong> internal
                team.
            </p>
            <p class="text-muted mb-0 small" style="line-height: 1.6;">
                If you have any questions or need assistance getting started, our Partner Support team is available through
                the portal.
            </p>
        </div>

        <p class="text-muted mt-5 mb-0">
            We’re excited to have you onboard.
        </p>

        <p class="text-muted mt-4 mb-0">
            Regards,<br>
            <strong>{{ $business_info['company_name'] }}</strong><br>
            Partner Operations Team
        </p>
    </div>
@endsection
