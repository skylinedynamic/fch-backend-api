@extends('emails.layouts.mail')

@section('title', 'Payment Verification Unsuccessful - ' . $siteName)

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $first_name }}</strong>,
        </h4>

        @if(!empty($is_receiver))
        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            The manual payment verification request for Invoice <strong>#{{ $invoice_id }}</strong> from <strong>{{ $payer_name }}</strong> has been marked as rejected.
        </p>
        @else
        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            We are writing to inform you that your manual payment verification request could not be approved by administration.
        </p>
        @endif

        {{-- Submission Details --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            Transaction Details</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            @if(!empty($is_receiver) && !empty($payer_name))
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Customer / Payer</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $payer_name }} @if(!empty($payer_email))<span style="font-size: 11px; color: #64748b; font-weight: normal;">({{ $payer_email }})</span>@endif</td>
            </tr>
            @endif
            @if(!empty($invoice_id))
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Invoice ID</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                    #{{ $invoice_id }}</td>
            </tr>
            @endif
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Amount</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 700; color: #dc2626; text-align: right; padding-right: 10px;">
                    {{ strtoupper($currency) }} {{ number_format($amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Date Processed</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                    {{ $date }}</td>
            </tr>
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Transaction / Reference ID</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                    {{ $transaction_id }}</td>
            </tr>
            @if(!empty($payment_method))
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Payment Method</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                    {{ $payment_method }}</td>
            </tr>
            @endif
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Description</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                    {{ ucwords(str_replace(['_', '-'], ' ', $description)) }}</td>
            </tr>
        </table>

        {{-- Reason Box --}}
        <div class="box-light"
            style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px;">
            <p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 700; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px;">
                Reason for Rejection:
            </p>
            <p style="margin: 0; line-height: 1.5; color: #b91c1c;">
                {{ $rejection_reason }}
            </p>
        </div>

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 24px;">
            @if(!empty($is_receiver))
            The customer has been notified with the rejection reason provided above.
            @else
            Please log into your portal to verify your invoice and resubmit payment with valid proof or choose an alternate payment method.
            @endif
        </p>

        <p style="color: #64748b; line-height: 1.5; margin-top: 40px; margin-bottom: 0;">
            Regards,<br>
            <strong style="color: #495057;">{{ $business_info['company_name'] }}</strong>
        </p>
    </div>
@endsection
