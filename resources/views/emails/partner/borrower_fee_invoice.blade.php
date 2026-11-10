@extends('emails.layouts.mail')

@section('title', 'New Borrower Created - Fee Invoice #' . ($invoice->invoice_id ?: $invoice->id))

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $hostName }}</strong>,
        </h4>

        <p style="line-height: 1.5; margin-bottom: 24px; color: #495057;">
            A new borrower account has been created by partner <strong>{{ $partner->company_name ?: $partner->full_name }}</strong>. Below are the borrower details and the generated Client Activation Fee invoice:
        </p>

        {{-- Borrower Details --}}
        <div class="box-light info-card-container"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 4px; margin-bottom: 24px; text-align: left; overflow: hidden;">
            <div class="info-card-header"
                style="background-color: #f0f7ff; padding: 16px 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 15px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
                Borrower Details
            </div>
            <div class="info-card-content" style="padding: 20px;">
                <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Borrower Name:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $borrower->full_name ?: ($borrower->first_name . ' ' . $borrower->last_name) }}</td>
                    </tr>
                    @if (!empty($borrower->email))
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Email:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $borrower->email }}</td>
                    </tr>
                    @endif
                    @if (!empty($borrower->phone))
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Phone:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $borrower->phone }}</td>
                    </tr>
                    @endif
                    @if (!empty($borrower->entity?->business_name) || !empty($borrower->business_name))
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Business Name:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $borrower->entity?->business_name ?: $borrower->business_name }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: none; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Partner / Organization:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 600; color: #1e3a8a; text-align: right; padding-right: 10px;">
                            {{ $partner->company_name ?: $partner->full_name }}</td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Client Activation Fee Invoice Details --}}
        <div class="box-light invoice-summary-card"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 4px; margin-bottom: 24px; text-align: left; overflow: hidden;">
            <div class="invoice-summary-header"
                style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                <span class="label invoice-summary-label"
                    style="display: block; margin-bottom: 6px; color: #64748b; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">
                    Client Activation Fee Amount
                </span>
                <span class="value invoice-summary-amount"
                    style="font-size: 26px; color: #1e3a8a; font-weight: 700;">${{ number_format($invoice->payment_amount, 2) }}</span>
            </div>
            <div class="invoice-summary-content" style="padding: 20px;">
                <table class="detail-table invoice-summary-table"
                    style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Invoice Number:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                            #{{ $invoice->invoice_id ?: $invoice->id }}</td>
                    </tr>
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Billed Partner:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $partner->company_name ?: $partner->full_name }}</td>
                    </tr>
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Date Issued:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $invoice->created_at ? $invoice->created_at->format('F j, Y') : date('F j, Y') }}</td>
                    </tr>
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: none; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Payment Status:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 600; color: #b45309; text-align: right; padding-right: 10px;">
                            {{ $invoice->status ?? 'Unpaid' }}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
@endsection
