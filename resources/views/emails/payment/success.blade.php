@extends('emails.layouts.mail')

@section('title', 'Payment Receipt - ' . config('app.name'))

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello <strong>{{ $first_name }}</strong>,</h4>

        <p style="line-height: 1.5; margin-bottom: 32px;">
            This is a confirmation that your payment was successfully processed.
        </p>

        {{-- Payment Details --}}
        <div class="section-label mb-2" style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">Payment Details</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Amount</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">{{ strtoupper($currency) }} {{ number_format($amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Date</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">{{ $date }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Transaction ID</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $transaction_id }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Description</td>
                <td class="value" style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">{{ $description }}</td>
            </tr>
        </table>

        {{-- Status Notification --}}
        <div class="box-light" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
            <p style="margin: 0; line-height: 1.5; color: #495057;">Your account has been updated, and no further action is required at this time.</p>
        </div>

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 32px;">
            If this payment is related to your <strong>{{ $business_info['company_name'] }}</strong> program enrollment or
            activation, our system will continue progressing your file automatically based on underwriting and tier
            requirements.
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
