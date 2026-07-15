@extends('emails.layouts.mail')

@section('title', 'Account Migrated - Paid Subscription')

@section('content')

    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <p style="line-height: 1.5; margin-bottom: 16px;">
            Hello <strong>{{ $user->name }}</strong>,
        </p>

        <p style="line-height: 1.5; margin-bottom: 16px;">
            We are writing to inform you that your partner account at <strong>{{ $business_info['company_name'] }}</strong> has been successfully migrated from a <strong>Free Trial</strong> to a paid subscription plan.
        </p>

        <p style="line-height: 1.5; margin-bottom: 32px; color: #64748b;">
            To finalize the migration and prevent any interruptions to your partner dashboard, please complete the enrollment payment process.
        </p>

        @php
            $displayAmount = (float) ($amount ?? 0);
        @endphp

        {{-- Enrollment Overview --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            Subscription & Payment Details</div>
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
                    Plan Enrollment Type</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $enrollmentType }}</td>
            </tr>
            @if ($displayAmount > 0)
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Payment Amount</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        ${{ number_format($displayAmount, 2) }}</td>
                </tr>
            @endif
        </table>

        {{-- Action Section --}}
        @if ($displayAmount > 0)
            <div class="box-primary"
                style="background-color: #f0f7ff; border: 1px solid #e0effe; border-radius: 8px; padding: 24px; margin-bottom: 24px; text-align: center;">
                <p style="line-height: 1.5; margin-bottom: 24px;">Please complete the secure payment process to activate your paid subscription.</p>
                <a href="{{ $paymentLink }}" class="btn"
                    style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Complete Payment</a>
                <p style="color: #64748b; font-size: 13px; margin-top: 24px; margin-bottom: 0;">Total Amount Due:
                    <strong style="color: #1e3a8a;">${{ number_format($displayAmount, 2) }}</strong>
                </p>
            </div>
        @else
            <div class="box-primary"
                style="background-color: #f0f7ff; border: 1px solid #e0effe; border-radius: 8px; padding: 24px; margin-bottom: 24px; text-align: center;">
                <p class="section-label mb-3"
                    style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">
                    Partner Portal Login:</p>
                <a href="{{ concatUrl(getPartnerDomain($partner_id), 'login', config('app.frontend_partner_url')) }}" class="btn"
                    style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Go
                    to Partner Dashboard</a>
            </div>
        @endif

        {{-- Note / Disclaimer --}}
        <div class="box-note"
            style="background-color: #f8fafc; border-left: 4px solid #1e3a8a; border-radius: 4px; padding: 16px 20px; margin-top: 32px; margin-bottom: 24px;">
            <p class="section-label mb-2"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                Please note:</p>
            <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-bottom: 12px;">
                Partner access remains active and your historical data from the Free Trial has been fully preserved.
            </p>
            <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin: 0;">
                If you have any questions or need assistance during this transition, our Partner Support team is available through the portal.
            </p>
        </div>

        <p style="color: #64748b; line-height: 1.5; margin-top: 32px; margin-bottom: 0;">
            Regards,<br>
            <strong style="color: #495057;">{{ $business_info['company_name'] }}</strong><br>
            Partner Operations Team
        </p>
    </div>
@endsection
