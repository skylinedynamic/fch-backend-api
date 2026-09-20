@extends('emails.layouts.mail')

@section('title', 'New Payment Received')

@section('content')
    <div class="content-header text-center mb-4" style="text-align: center; margin-bottom: 24px;">
        <h4 class="appointment-title" style="font-size: 20px; font-weight: 700; color: #1e3a8a; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 1px;">New Payment Received</h4>
        <p style="color: #64748b; font-size: 14px; margin: 0; line-height: 1.5;">
            A new payment has been successfully processed on <strong>{{ $business_info['company_name'] }}</strong>.
        </p>
    </div>

    <div class="info-card-container box-light" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header" style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Payment Details
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; table-layout: fixed; word-wrap: break-word; margin-bottom: 0;">
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Payer Name</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px; word-wrap: break-word;">{{ $user_name }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Payer Email</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #1e3a8a; text-align: right; padding-right: 10px; word-wrap: break-word;"><a href="mailto:{{ $user_email }}" style="color: #1e3a8a; text-decoration: none;">{{ $user_email }}</a></td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Transaction ID</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px; word-wrap: break-word;">{{ $transaction_id }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Date</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px; word-wrap: break-word;">{{ $date }}</td>
                </tr>
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Amount</td>
                    <td class="value" style="padding: 10px 0; border-bottom: none; font-size: 18px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px; word-wrap: break-word;"><span class="amount-value" style="color: #1e3a8a;"><strong>{{ strtoupper($currency) }}
                                 {{ number_format($amount, 2) }}</strong></span></td>
                </tr>
            </table>
        </div>
    </div>
@endsection
