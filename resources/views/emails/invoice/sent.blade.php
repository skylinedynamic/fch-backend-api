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
        <div class="section-label mb-2">Invoice Summary</div>
        <table class="detail-table">
            <tr>
                <td class="label">Invoice Number</td>
                <td class="value">#{{ $invoice->invoice_id }}</td>
            </tr>
            <tr>
                <td class="label">Date Issued</td>
                <td class="value">{{ $invoice->created_at->format('F j, Y') }}</td>
            </tr>
            <tr>
                <td class="label">Amount Due</td>
                <td class="value">${{ number_format($invoice->payment_amount, 2) }}</td>
            </tr>
        </table>

        {{-- Action Section --}}
        <div class="box-primary">
            <p class="mb-4">You can securely view and pay your invoice online through our partner portal.</p>
            <a href="{{ config('app.frontend_partner_url') }}/invoices/{{ $invoice->id }}" class="btn">View & Pay
                Invoice</a>
            <p class="text-muted small mt-4 mb-0">Invoice Status: <strong>Pending Payment</strong></p>
        </div>

        {{-- Note --}}
        <div class="box-note">
            <p class="section-label mb-2">Important Note</p>
            <p class="text-muted mb-0 small" style="line-height: 1.6;">
                For your records, a PDF copy of this invoice has been attached to this email. If you have already made this
                payment, please disregard this notice.
            </p>
        </div>

        <p class="text-muted mt-5 mb-0">
            Thank you for your business.
        </p>
        <p class="text-muted mt-4 mb-0">
            Regards,<br>
            <strong>{{ config('app.name', 'Four Corner Holdings, LLC') }}</strong><br>
            Billing Department
        </p>
    </div>
@endsection
