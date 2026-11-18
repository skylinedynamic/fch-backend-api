@extends('emails.layouts.mail')

@section('title', $siteName . ' - Activate Your Account')

@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $user->name }}!</h4>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        Your account has been created with <strong>{{ $siteName }}</strong>.
        To finalize your setup and activate your account, please complete the payment below.
    </p>

<!-- Information List -->
<div class="box-light info-card-container" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
    <div class="info-card-header" style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">Payment Required</div>
    <div class="info-card-content" style="padding: 20px;">
        <table class="info-table" style="width: 100%; border-collapse: collapse; table-layout: fixed; word-wrap: break-word; margin-bottom: 0;">
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Full Name</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px; word-wrap: break-word;">{{ $user->name }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Email Address</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #1e3a8a; text-align: right; padding-right: 10px; word-wrap: break-word;">{{ $user->email }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Payment Amount</td>
                <td class="value amount-value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 600; color: #1e3a8a; text-align: right; padding-right: 10px;">${{ number_format($amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Temporary Password</td>
                <td class="value" style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px; word-wrap: break-word;"><span class="highlight-value" style="background-color: #eff6ff; padding: 4px 8px; border-radius: 4px; font-family: monospace;">{{ $plainPassword }}</span></td>
            </tr>
        </table>
    </div>
</div>

    @if (!empty($paymentLink))
    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <p class="highlight" style="color: #1e3a8a; font-weight: 700; margin-bottom: 20px; font-size: 14px;">Please complete
            your payment to finalize your account setup.</p>
        <a href="{{ $paymentLink }}" class="btn"
            style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Pay
            ${{ number_format($amount, 2) }} & Setup Account</a>
    </div>
    @endif
@endsection