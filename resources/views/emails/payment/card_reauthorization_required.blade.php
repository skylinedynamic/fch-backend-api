@extends('emails.layouts.mail')

@section('title', 'Action Required: Update Your Payment Card - ' . $siteName)

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $first_name }}</strong>,
        </h4>

        <p style="line-height: 1.5; margin-bottom: 32px;">
            We were unable to process your scheduled payment because the card saved on your account can no longer be
            charged. To keep your payments running, please add your card again.
        </p>

        {{-- Payment Details --}}
        <div class="section-label mb-2"
            style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            Payment Details</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Amount Due</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                    {{ strtoupper($currency) }} {{ number_format($amount, 2) }}</td>
            </tr>
            @if ($invoice_id)
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Invoice</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $invoice_id }}</td>
                </tr>
            @endif
            <tr>
                <td class="label"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                    Date</td>
                <td class="value"
                    style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                    {{ $date }}</td>
            </tr>
            @if ($card_no)
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Card on File</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">
                        {{ $card_no }}</td>
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
            <p style="margin: 0; line-height: 1.5; color: #b91c1c;">Your card details have not changed on your side — our
                payment provider simply needs them entered again before your next payment can be taken.</p>
        </div>

        @if ($portal_url)
            <div class="btn-container" style="margin: 30px 0; text-align: center;">
                <a href="{{ $portal_url }}" class="btn"
                    style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Update
                    Payment Method</a>
            </div>
        @endif

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 32px;">
            Until your card is updated, this payment will remain outstanding and further automatic attempts will not
            succeed. No charge has been made, and you have not been billed twice.
        </p>

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 24px;">
            If you have any questions, please contact our support team through your portal.
        </p>

        <p style="color: #64748b; line-height: 1.5; margin-top: 40px; margin-bottom: 0;">
            Thank you for your business.
        </p>

        <p style="color: #64748b; line-height: 1.5; margin-top: 24px; margin-bottom: 0;">
            <strong style="color: #495057;">{{ $business_info['company_name'] }}</strong><br>
            Business Credit &amp; Funding Platform
        </p>
    </div>
@endsection
