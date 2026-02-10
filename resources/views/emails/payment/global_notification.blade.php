@extends('emails.layouts.mail')

@section('title', 'New Payment Received')

@section('content')
    <div class="content-header text-center mb-4">
        <h4 class="appointment-title">New Payment Received</h4>
        <p class="text-muted">
            A new payment has been successfully processed on <strong>{{ $business_info['company_name'] }}</strong>.
        </p>
    </div>

    <div class="info-card-container box-light">
        <div class="info-card-header">
            Payment Details
        </div>
        <div class="info-card-content">
            <table class="info-table">
                <tr>
                    <td class="label">Payer Name</td>
                    <td class="value">{{ $user_name }}</td>
                </tr>
                <tr>
                    <td class="label">Payer Email</td>
                    <td class="value"><a href="mailto:{{ $user_email }}">{{ $user_email }}</a></td>
                </tr>
                <tr>
                    <td class="label">Transaction ID</td>
                    <td class="value">{{ $transaction_id }}</td>
                </tr>
                <tr>
                    <td class="label">Date</td>
                    <td class="value">{{ $date }}</td>
                </tr>
                <tr>
                    <td class="label">Amount</td>
                    <td class="value"><span class="amount-value"><strong>{{ strtoupper($currency) }}
                                {{ number_format($amount, 2) }}</strong></span></td>
                </tr>
            </table>
        </div>
    </div>
@endsection
