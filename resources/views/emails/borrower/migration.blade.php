@extends('emails.layouts.mail')

@section('title', $siteName . ' - Account Migrated')

@section('content')
<h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $user->name }}!</h4>

<p style="line-height: 1.5; margin-bottom: 24px;">
    We are writing to inform you that your client account at <strong>{{ $siteName }}</strong> has been successfully migrated from a <strong>Free Trial</strong> to a paid subscription plan.
</p>

<p style="line-height: 1.5; margin-bottom: 24px;">
    To finalize this transition and prevent any interruptions to your portal access, please complete the secure payment process below.
</p>

<!-- Information List -->
<div class="box-light info-card-container" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
    <div class="info-card-header" style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">Subscription Details</div>
    <div class="info-card-content" style="padding: 20px;">
        <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Full Name</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $user->name }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Email Address</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #1e3a8a; text-align: right; padding-right: 10px;">{{ $user->email }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Payment Amount</td>
                <td class="value amount-value" style="padding: 10px 0; border-bottom: none; font-size: 16px; font-weight: 600; color: #1e3a8a; text-align: right; padding-right: 10px;">${{ number_format($amount, 2) }}</td>
            </tr>
        </table>
    </div>
</div>

<div class="btn-container" style="margin: 30px 0; text-align: center;">
    <p class="highlight" style="color: #1e3a8a; font-weight: 700; margin-bottom: 20px; font-size: 14px;">Please complete your payment to activate your paid subscription.</p>
    <a href="{{ $paymentLink }}" class="btn" style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Complete Payment</a>
</div>
@endsection
