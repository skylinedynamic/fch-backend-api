@extends('emails.layouts.mail')

@section('title', 'Free Trial Expiry Date Updated - ' . $siteName)

@section('content')

    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $user->name }}</strong>,
        </h4>

        <p style="line-height: 1.5; margin-bottom: 16px;">
            We are writing to inform you that the free trial expiration date for your partner account at <strong>{{ $business_info['company_name'] }}</strong> has been updated.
        </p>

        {{-- Trial Notice --}}
        <div
            style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px;">
            <p
                style="font-size: 13px; font-weight: 700; color: #15803d; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px;">
                FREE TRIAL EXPIRY UPDATED
            </p>
            <p style="color: #166534; font-size: 13px; line-height: 1.6; margin: 0;">
                Your partner free trial access has been updated. Your new trial access expiration date is
                <strong>{{ !empty($trialEndDate) ? \Carbon\Carbon::parse($trialEndDate)->format('F j, Y') : 'N/A' }}</strong>.
            </p>
        </div>

        {{-- Details Table --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            Trial Details</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Partner Name</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $user->name }}</td>
            </tr>
            @if (!empty($partner?->company_name))
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Company</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $partner->company_name }}</td>
                </tr>
            @endif
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Account Type</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                    Free Trial</td>
            </tr>
            @if (!empty($oldTrialEndDate))
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Previous Expiry Date</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #64748b; text-decoration: line-through; text-align: right; padding-right: 10px;">
                        {{ \Carbon\Carbon::parse($oldTrialEndDate)->format('F j, Y') }}</td>
                </tr>
            @endif
            @if (!empty($trialEndDate))
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        New Expiry Date</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700; color: #16a34a; text-align: right; padding-right: 10px;">
                        {{ \Carbon\Carbon::parse($trialEndDate)->format('F j, Y') }}</td>
                </tr>
            @endif
        </table>

        {{-- Action Section --}}
        <div class="box-primary"
            style="background-color: #f0f7ff; border: 1px solid #e0effe; border-radius: 8px; padding: 24px; margin-bottom: 24px; text-align: center;">
            <p class="section-label mb-3"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">
                Partner Portal:</p>
            <a href="{{ concatUrl(getPartnerDomain($partner_id), 'login', config('app.frontend_partner_url')) }}" class="btn"
                style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Go
                to Partner Dashboard</a>
        </div>

        {{-- Note / Disclaimer --}}
        <div class="box-note"
            style="background-color: #f8fafc; border-left: 4px solid #1e3a8a; border-radius: 4px; padding: 16px 20px; margin-top: 32px; margin-bottom: 24px;">
            <p class="section-label mb-2"
                style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                Please note:</p>
            <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin: 0;">
                During your free trial period, you can continue accessing all partner features and managing your clients. If you have any questions or would like to transition to a paid plan, please reach out to your administrator.
            </p>
        </div>

        <p style="color: #64748b; line-height: 1.5; margin-top: 32px; margin-bottom: 0;">
            Regards,<br>
            <strong style="color: #495057;">{{ $business_info['company_name'] }}</strong><br>
            Partner Operations Team
        </p>
    </div>
@endsection
