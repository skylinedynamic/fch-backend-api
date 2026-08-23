@extends('emails.layouts.mail')

@section('title', 'Payment Approved - ' . $siteName)

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $first_name }}</strong>,
        </h4>

        @if(!empty($is_receiver))
        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            The manual payment submission of <strong>{{ strtoupper($currency) }} {{ number_format($amount, 2) }}</strong> for Invoice <strong>#{{ $invoice_id }}</strong> from <strong>{{ $payer_name }}</strong> has been verified and approved.
        </p>
        @else
        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            Great news! Your manual payment submission has been verified and officially approved by administration. Your invoice is now marked as <strong>Paid</strong>.
        </p>
        @endif

        {{-- Payment Details --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            Receipt Details</div>
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
                    Amount Paid</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 700; color: #047857; text-align: right; padding-right: 10px;">
                    {{ strtoupper($currency) }} {{ number_format($amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Approval Date</td>
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
            @if(!empty($approval_note))
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-top: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Note</td>
                <td class="value"
                    style="padding: 10px 0; border-top: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                    {{ $approval_note }}</td>
            </tr>
            @endif
        </table>

        {{-- Status Notification --}}
        @if(!empty($is_receiver))
        <div class="box-light"
            style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px;">
            <p style="margin: 0; line-height: 1.5; color: #065f46; font-weight: 500;">
                <strong style="color: #047857;">Payment Approved.</strong> The invoice has been marked as Paid in your portal and customer status updated.
            </p>
        </div>
        @else
        <div class="box-light"
            style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px;">
            <p style="margin: 0; line-height: 1.5; font-weight: 500;">
                <strong style="color: #047857;">Status: Approved & Paid.</strong> Your payment has been credited to your account and your enrollment/subscription is active.
            </p>
        </div>
        @endif

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 24px;">
            @if(!empty($is_receiver))
            This transaction record is accessible in your Payments section.
            @else
            If you have any questions, feel free to reach out to our support team through your portal.
            @endif
        </p>

        <p style="color: #64748b; line-height: 1.5; margin-top: 40px; margin-bottom: 0;">
            Thank you for your business,<br>
            <strong style="color: #495057;">{{ $business_info['company_name'] }}</strong>
        </p>
    </div>
@endsection
