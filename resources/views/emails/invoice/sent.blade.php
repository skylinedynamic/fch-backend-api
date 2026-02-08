@extends('emails.layouts.mail')

@section('title', 'Invoice #' . $invoice->invoice_id . ' - ' . config('app.name'))

@section('content')
<div class="description-card">
    <p class="mb-3">
        Hello <strong>{{ $invoice->customer_name }}</strong>,
    </p>

    <p class="mb-5 text-muted">
        An invoice has been generated for your account. Please find the details below and proceed with the payment to
        avoid any service interruptions.
    </p>

    {{-- Invoice Details --}}
    <div class="box-light invoice-summary-card">
        <div class="invoice-summary-header">
            <span class="label invoice-summary-label">Amount Due</span>
            <span class="value invoice-summary-amount">${{ number_format($invoice->payment_amount, 2) }}</span>
        </div>
        <div class="invoice-summary-content">
            <table class="detail-table invoice-summary-table">
                <tr>
                    <td class="label">Invoice Number</td>
                    <td class="value">#{{ $invoice->invoice_id }}</td>
                </tr>
                <tr>
                    <td class="label">Date Issued</td>
                    <td class="value">{{ $invoice->created_at->format('F j, Y') }}</td>
                </tr>
            </table>
        </div>
    </div>

    {{-- Action Section --}}
    <div class="box-primary">
        <p class="mb-4">You can securely view and pay your invoice online through our partner portal.</p>
        <a href="{{ config('app.frontend_partner_url') }}/invoices/{{ $invoice->id }}" class="btn">View & Pay
            Invoice</a>
        <p class="text-muted small mt-4 mb-0">Invoice Status: <strong>Pending Payment</strong></p>
    </div>


    <p class="text-muted mt-5 mb-0">
        Thank you for your business.
    </p>
    <p class="text-muted mt-4 mb-0">
        Regards,<br>
        <strong>{{ config('app.name') }}</strong><br>
        Billing Department
    </p>
</div>
@endsection