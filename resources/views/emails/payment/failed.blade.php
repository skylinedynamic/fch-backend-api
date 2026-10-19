@extends('emails.layouts.mail')

@section('title', 'Payment Failed - ' . $siteName)

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $first_name }}</strong>,
        </h4>

        <p style="line-height: 1.5; margin-bottom: 32px;">
            @if (!empty($no_saved_card))
                We could not take the automatic payment for your invoice because there is no default payment method on your account.
            @else
                We regret to inform you that your recent payment attempt was unsuccessful.
            @endif
        </p>

        {{-- Payment Details --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            Payment Details</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Amount</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                    {{ strtoupper($currency) }} {{ number_format($amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Date</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                    {{ $date }}</td>
            </tr>
            @if (!empty($invoice_id))
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Invoice</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $invoice_id }}</td>
            </tr>
            @endif
            @if (!empty($transaction_id))
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Transaction ID</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $transaction_id }}</td>
            </tr>
            @endif
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Description</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                    {{ $description }}</td>
            </tr>
        </table>

        {{-- Status Notification --}}
        <div class="box-light"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
            <p style="margin: 0; line-height: 1.5; color: #b91c1c;">Please review your payment method and try again, or contact support for assistance.</p>
        </div>

        @if (!empty($portal_url))
            <div class="btn-container" style="margin: 30px 0; text-align: center;">
                <a href="{{ $portal_url }}" class="btn"
                    style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Update
                    Payment Method or Pay Now</a>
            </div>
        @endif

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 32px;">
            If this payment is related to your <strong>{{ $business_info['company_name'] }}</strong> program enrollment or
            activation, your access may be limited until the payment is successfully resolved.
        </p>

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 24px;">
            If you have any questions or believe this payment was made in error, please contact our support team through
            your portal.
        </p>

        <p style="color: #64748b; line-height: 1.5; margin-top: 40px; margin-bottom: 0;">
            Thank you for your business.
        </p>

        <p style="color: #64748b; line-height: 1.5; margin-top: 24px; margin-bottom: 0;">
            <strong style="color: #495057;">{{ $business_info['company_name'] }}</strong><br>
            Business Credit & Funding Platform
        </p>
    </div>
@endsection
