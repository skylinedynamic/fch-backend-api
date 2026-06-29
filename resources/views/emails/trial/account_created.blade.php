@extends('emails.layouts.mail')

@section('title', 'Welcome to ' . $siteName)

@section('content')

    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <p style="line-height: 1.5; margin-bottom: 16px;">
            Hello <strong>{{ $user->name }}</strong>,
        </p>

        <p style="line-height: 1.5; margin-bottom: 16px;">
            Welcome to <strong>{{ $business_info['company_name'] }}</strong>.
        </p>

        <p style="line-height: 1.5; margin-bottom: 32px; color: #64748b;">
            Your partner free trial account has been successfully created, and you now have access to the
            <strong>{{ $business_info['company_name'] }} Partner Portal</strong>.
        </p>

        {{-- Next Steps --}}
        <div class="box-light"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
            <p class="section-label mb-3"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">
                What you can do next:</p>
            <ul style="margin: 0; padding-left: 20px; color: #495057; line-height: 1.6; font-size: 14px;">
                <li>Log in to your Partner Dashboard</li>
                <li>Add and manage clients</li>
                <li>Track client progress through tiers and underwriting</li>
                <li>View commission activity (as applicable)</li>
                <li>Access partner training and resources</li>
            </ul>
        </div>

        {{-- Account Overview --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            Account Overview</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Partner Name</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $user->name }}</td>
            </tr>
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Account Type</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                    Free Trial</td>
            </tr>
            @if (!empty($trialEndDate))
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Trial End Date</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $trialEndDate }}</td>
                </tr>
            @endif
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

        {{-- Action Section --}}
        <div class="box-primary"
            style="background-color: #f0f7ff; border: 1px solid #e0effe; border-radius: 8px; padding: 24px; margin-bottom: 24px; text-align: center;">
            <p class="section-label mb-3"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">
                Partner Portal Login:</p>
            <a href="{{ concatUrl(getPartnerDomain(), 'login', config('app.frontend_partner_url')) }}" class="btn"
                style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Go
                to Partner Dashboard</a>
            <p style="color: #64748b; font-size: 12px; margin-top: 24px; margin-bottom: 0; line-height: 1.5;">
                For security reasons, if this is your first login, you may be prompted to set or confirm your password.
            </p>
        </div>

        {{-- Note / Disclaimer --}}
        <div class="box-note"
            style="background-color: #f8fafc; border-left: 4px solid #1e3a8a; border-radius: 4px; padding: 16px 20px; margin-top: 32px; margin-bottom: 24px;">
            <p class="section-label mb-2"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                Please note:</p>
            <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-bottom: 12px;">
                Partner access is role-based and read-only for underwriting decisions. All underwriting, tier advancement,
                and funding decisions remain managed by the <strong>{{ $business_info['company_name'] }}</strong> internal
                team.
            </p>
            <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin: 0;">
                If you have any questions or need assistance getting started, our Partner Support team is available through
                the portal.
            </p>
        </div>

        <p style="color: #64748b; line-height: 1.5; margin-top: 32px; margin-bottom: 0;">
            We’re excited to have you onboard.
        </p>

    </div>
@endsection
