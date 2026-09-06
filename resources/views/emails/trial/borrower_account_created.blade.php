@extends('emails.layouts.mail')

@section('title', 'Welcome to ' . $siteName)

@section('content')

    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $user->name }}</strong>,
        </h4>

        <p style="line-height: 1.5; margin-bottom: 16px;">
            Welcome to <strong>{{ $business_info['company_name'] }}</strong>.
        </p>

        <p style="line-height: 1.5; margin-bottom: 32px; color: #64748b;">
            Your <strong>limited free plan account</strong> has been successfully created. You now have full access to
            the
            <strong>{{ $business_info['company_name'] }} Client Portal</strong> for the duration of your free plan period.
        </p>

        {{-- Trial Notice --}}
        <div
            style="background-color: #fff7ed; border: 1px solid #fed7aa; border-left: 4px solid #f97316; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px;">
            <p
                style="font-size: 13px; font-weight: 700; color: #c2410c; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px;">
                Limited Free Plan Active
            </p>
            <p style="color: #7c3d12; font-size: 13px; line-height: 1.6; margin: 0;">
                You are on a <strong>Limited Free Plan</strong>. No payment is required at this time.
                @if (!empty($trialEndDate))
                    Your free plan access expires on <strong>{{ \Carbon\Carbon::parse($trialEndDate)->format('F j, Y') }}</strong>.
                @endif
            </p>
        </div>

        {{-- What you can do --}}
        <div class="box-light"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
            <p class="section-label mb-3"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">
                What you can do next:</p>
            <ul style="margin: 0; padding-left: 20px; color: #495057; line-height: 1.6; font-size: 14px;">
                <li>Log in to your Client Portal</li>
                <li>Review your credit profile and tier progress</li>
                <li>Submit documents and track application status</li>
                <li>Connect with your advisor for guidance</li>
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
                    Name</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $user->name }}
                </td>
            </tr>
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Email</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $user->email }}
                </td>
            </tr>
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Account Type</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #f97316; text-align: right; padding-right: 10px;">
                    Limited Free Plan</td>
            </tr>
            @if (!empty($trialEndDate))
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Limited Free Plan Expires</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #c2410c; text-align: right; padding-right: 10px;">
                        {{ \Carbon\Carbon::parse($trialEndDate)->format('F j, Y') }}
                    </td>
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

        {{-- Login Button --}}
        <div class="box-primary"
            style="background-color: #f0f7ff; border: 1px solid #e0effe; border-radius: 8px; padding: 24px; margin-bottom: 24px; text-align: center;">
            <p class="section-label mb-3"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">
                Access Your Portal:</p>
            <a href="{{ concatUrl(getBorrowerDomain($partner_id), 'login', config('app.frontend_borrower_url')) }}" class="btn"
                style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Go
                to Client Portal</a>
            <p style="color: #64748b; font-size: 12px; margin-top: 16px; margin-bottom: 0; line-height: 1.5;">
                For security reasons, please change your password after your first login.
            </p>
        </div>

        {{-- Note --}}
        <div class="box-note"
            style="background-color: #f8fafc; border-left: 4px solid #f97316; border-radius: 4px; padding: 16px 20px; margin-top: 24px; margin-bottom: 24px;">
            <p class="section-label mb-2"
                style="font-size: 13px; font-weight: 700; color: #c2410c; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                Free Plan Reminder:</p>
            <p style="color: #7c3d12; font-size: 12px; line-height: 1.6; margin: 0;">
                This is a Limited Free Plan account. No payment is required during the free plan period.
                @if (!empty($trialEndDate))
                    After <strong>{{ \Carbon\Carbon::parse($trialEndDate)->format('F j, Y') }}</strong>, continued access will
                    require an active subscription.
                @endif
                If you have any questions, please reach out to our support team.
            </p>
        </div>

        <p style="color: #64748b; line-height: 1.5; margin-top: 32px; margin-bottom: 0;">
            We're excited to have you onboard.
        </p>

    </div>
@endsection