@extends('emails.layouts.mail')

@section('title', 'Invoice #' . $invoice->invoice_id . ' - ' . $siteName)

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $invoice->customer_name }}</strong>,
        </h4>

        <p style="line-height: 1.5; margin-bottom: 32px; color: #64748b;">
            An invoice has been generated for your account. Please find the details below and proceed with the payment to
            avoid any service interruptions.
        </p>

        {{-- Invoice Details --}}
        <div class="box-light invoice-summary-card"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 30px; text-align: left; overflow: hidden;">
            <div class="invoice-summary-header"
                style="background-color: #f0f7ff; padding: 25px 20px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                <span class="label invoice-summary-label"
                    style="display: block; margin-bottom: 8px; color: #64748b; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">
                    @if ($invoice->status === 'Paid')
                        Amount Paid
                    @else
                        Amount Due
                    @endif
                </span>
                <span class="value invoice-summary-amount"
                    style="font-size: 28px; color: #1e3a8a; font-weight: 700;">${{ number_format($invoice->payment_amount, 2) }}</span>
            </div>
            <div class="invoice-summary-content" style="padding: 20px;">
                <table class="detail-table invoice-summary-table"
                    style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                    <tr>
                        <td class="label"
                            style="padding: 12px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%;">
                            Invoice Number</td>
                        <td class="value"
                            style="padding: 12px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; font-weight: 600; color: #0f172a; text-align: right;">
                            #{{ $invoice->invoice_id }}</td>
                    </tr>
                    <tr>
                        <td class="label"
                            style="padding: 12px 0; border-bottom: none; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%;">
                            Date Issued</td>
                        <td class="value"
                            style="padding: 12px 0; border-bottom: none; font-size: 14px; font-weight: 600; color: #0f172a; text-align: right;">
                            {{ $invoice->created_at->format('F j, Y') }}</td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- The last installment, adjusted for rounding (BUG-049) --}}
        @php($finalInstallment = $invoice->finalInstallmentNote())
        @if ($finalInstallment)
            <div class="box-light"
                style="background-color: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #d97706; border-radius: 8px; padding: 16px 20px; margin-bottom: 30px; text-align: left;">
                <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #0f172a;">
                    {{ __('invoice.final_installment.mail_title') }}
                </p>
                <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #64748b;">
                    {{ __('invoice.final_installment.mail_note', [
                        'difference' => $finalInstallment['difference'],
                        'monthly' => $finalInstallment['monthly'],
                        'count' => $finalInstallment['count'],
                        'total' => $finalInstallment['total'],
                    ]) }}
                </p>
            </div>
        @endif

        {{-- Action Section --}}
        <div class="box-primary"
            style="background-color: #f0f7ff; border: 1px solid #e0effe; border-radius: 8px; padding: 24px; margin-bottom: 24px; text-align: center;">
            @if ($invoice->status === 'Paid')
                <p style="line-height: 1.5; margin-bottom: 24px;">You can securely access and view your paid invoice in the
                    Invoices section.</p>
                <a href="{{ concatUrl($invoice->is_partner_paying ? getPartnerDomain($invoice->partner_id) : getBorrowerDomain($invoice->partner_id), 'invoices', $invoice->is_partner_paying ? config('app.frontend_partner_url') : config('app.frontend_borrower_url')) }}" class="btn"
                    style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View
                    Invoice</a>
                <p style="color: #64748b; font-size: 13px; margin-top: 24px; margin-bottom: 0;">Invoice Status: <strong
                        style="color: #28a745;">Paid</strong></p>
            @else
                <p style="line-height: 1.5; margin-bottom: 24px;">You can securely view and pay your invoice online through
                    the Invoice section.</p>
                <a href="{{ concatUrl($invoice->is_partner_paying ? getPartnerDomain($invoice->partner_id) : getBorrowerDomain($invoice->partner_id), 'invoices', $invoice->is_partner_paying ? config('app.frontend_partner_url') : config('app.frontend_borrower_url')) }}" class="btn"
                    style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View
                    & Pay
                    Invoice</a>
                <p style="color: #64748b; font-size: 13px; margin-top: 24px; margin-bottom: 0;">Invoice Status:
                    <strong>{{ $invoice->status ?? 'Pending Payment' }}</strong>
                </p>
            @endif
        </div>


        <p style="color: #64748b; line-height: 1.5; margin-top: 32px; margin-bottom: 0;">
            Thank you for your business.
        </p>
        <p style="color: #64748b; line-height: 1.5; margin-top: 24px; margin-bottom: 0;">
            Regards,<br>
            <strong style="color: #495057;">{{ $siteName ?? config('app.name') }}</strong><br>
            Billing Department
        </p>
    </div>
@endsection
