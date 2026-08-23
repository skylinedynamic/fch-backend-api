@extends('emails.layouts.mail')

@section('title', 'Payment Verification Submitted - ' . $siteName)

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $first_name }}</strong>,
        </h4>

        @if(!empty($is_receiver))
        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            A new manual payment confirmation of <strong>{{ strtoupper($currency) }} {{ number_format($amount, 2) }}</strong> has been submitted by <strong>{{ $payer_name }}</strong> for Invoice <strong>#{{ $invoice_id }}</strong> and is awaiting your review and approval.
        </p>
        @else
        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            We have received your payment confirmation request. Your submission has been placed in our manual verification queue. Administration will review your transaction details and notify you once confirmed.
        </p>
        @endif

        {{-- Submission Details --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            Submission Details</div>
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
                    Amount Due</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                    {{ strtoupper($currency) }} {{ number_format($amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Date Submitted</td>
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
            @if(!empty($bank_name))
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Bank Transferred To</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                    {{ $bank_name }} @if(!empty($account_name))({{ $account_name }})@endif</td>
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

        {{-- Status Notification --}}
        @if(!empty($is_receiver))
        <div class="box-light"
            style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px;">
            <p style="margin: 0 0 14px 0; line-height: 1.5; color: #92400e; font-weight: 500;">
                <strong style="color: #b45309;">Action Required: Pending Verification.</strong> Please review transaction details in your portal and approve or reject this payment.
            </p>
            @if(!empty($payment_url))
            <div style="margin-top: 14px;">
                <a href="{{ $payment_url }}"
                    style="display: inline-block; background-color: #72b3a6; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 13px;">
                    Review Payment Details &rarr;
                </a>
            </div>
            @endif
        </div>
        @else
        <div class="box-light"
            style="background-color: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px;">
            <p style="margin: 0; line-height: 1.5; font-weight: 500;">
                <strong style="color: #0284c7;">Status: Processing Verification.</strong> No further action is required from you at this time. Once verified, you will receive an approval confirmation.
            </p>
        </div>
        @endif

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 24px;">
            @if(!empty($is_receiver))
            You can verify this transfer directly from your Payments management section in the portal.
            @else
            If you have any questions or need to update your submitted payment proof, please contact our support team through your portal.
            @endif
        </p>
    </div>
@endsection
