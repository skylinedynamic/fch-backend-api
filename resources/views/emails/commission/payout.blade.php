@extends('emails.layouts.mail')

@section('title', 'Commission Payout Processed')

@section('content')
    <style>
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .details-table th,
        .details-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .details-table th {
            background-color: #f8f9fa;
            width: 40%;
        }

        .note-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
    </style>

    <div class="content">
        <p>Dear <strong>{{ $commission->partner->full_name ?? ($commission->partner->company_name ?? 'Partner') }}</strong>,</p>

        <p>We are pleased to inform you that your commission payout has been successfully processed.</p>

        <table class="details-table">
            <tr>
                <th>Invoice ID</th>
                <td>{{ $invoice->invoice_id }}</td>
            </tr>
            <tr>
                <th>Amount Paid</th>
                <td>{{ $commission->currency_symbol }}{{ number_format($commission->partner_commission_amount, 2) }}
                    {{ $commission->currency }}</td>
            </tr>
            <tr>
                <th>Payment Method</th>
                <td>{{ $invoice->payments->first()->payment_type ?? 'Bank Transfer' }}</td>
            </tr>
            <tr>
                <th>Date Processed</th>
                <td>{{ \Carbon\Carbon::parse($commission->payout_date)->format('F d, Y') }}</td>
            </tr>
        </table>

        @if ($note)
            <div class="note-box">
                <h4 style="margin-top:0; color:#856404;">A note regarding your payout:</h4>
                <p style="margin-bottom:0; color:#856404;">{{ $note }}</p>
            </div>
        @endif

        <p>If you have any questions about this payout, please contact our support team.</p>

        <p>Best regards,<br>
            {{ $siteName }} Team</p>
    </div>
@endsection
