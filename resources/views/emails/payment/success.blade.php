@extends('emails.layouts.mail')

@section('title', 'Payment Receipt - ' . config('app.name'))

@section('content')
    <div class="description-card">
        <h4 class="mb-3">Hello <strong>{{ $first_name }}</strong>,</h4>

        <p class="mb-5">
            This is a confirmation that your payment was successfully processed.
        </p>

        {{-- Payment Details --}}
        <div class="section-label mb-2">Payment Details</div>
        <table class="detail-table">
            <tr>
                <td class="label">Amount</td>
                <td class="value">{{ strtoupper($currency) }} {{ number_format($amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Date</td>
                <td class="value">{{ $date }}</td>
            </tr>
            <tr>
                <td class="label">Transaction ID</td>
                <td class="value">{{ $transaction_id }}</td>
            </tr>
            <tr>
                <td class="label">Description</td>
                <td class="value">{{ $description }}</td>
            </tr>
        </table>

        {{-- Status Notification --}}
        <div class="box-light">
            <p class="mb-0">Your account has been updated, and no further action is required at this time.</p>
        </div>

        <p class="mt-4 text-muted small" style="line-height: 1.6;">
            If this payment is related to your <strong>{{ $business_info['company_name'] }}</strong> program enrollment or
            activation, our system will continue progressing your file automatically based on underwriting and tier
            requirements.
        </p>

        <p class="mt-4 text-muted small" style="line-height: 1.6;">
            If you have any questions or believe this payment was made in error, please contact our support team through
            your portal.
        </p>

        <p class="text-muted mt-5 mb-0">
            Thank you for your business.
        </p>

        <p class="text-muted mt-4 mb-0">
            <strong>{{ $business_info['company_name'] }}</strong><br>
            Business Credit & Funding Platform
        </p>
    </div>
@endsection
