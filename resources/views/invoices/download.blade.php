<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoice->invoice_id }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #333;
            line-height: 1.4;
            font-size: 13px;
            margin: 0;
            padding: 10px;
        }

        .logo {
            width: 200px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-section td {
            vertical-align: middle;
        }

        .company-info-text {
            text-align: right;
            font-size: 13px;
            color: #6b7280;
        }

        .company-info-text strong {
            color: #111827;
        }

        .address-container {
            margin-top: 20px;
            margin-bottom: 20px;
            border-top: 1px dashed #e5e7eb;
            padding-top: 20px;
        }

        .section-header {
            color: #6b7280;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }

        .address-box p {
            margin: 2px 0;
            color: #6b7280;
            font-size: 13px;
        }

        .address-box .name {
            font-weight: bold;
            font-size: 14px;
            color: #374151;
            margin-bottom: 4px;
        }

        .summary-bar {
            margin-bottom: 30px;
            margin-top: 20px;
        }

        .summary-bar td {
            padding: 0px 0px;
            vertical-align: top;
        }

        .summary-bar .label {
            color: #6b7280;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .summary-bar .value {
            font-weight: bold;
            color: #374151;
            font-size: 14px;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
            background-color: #fffbeb;
            color: #92400e;
        }

        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }

        .items-table thead th {
            background-color: #f9fafb;
            text-align: left;
            padding: 10px 15px;
            font-size: 11px;
            text-transform: uppercase;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }

        .items-table td {
            padding: 15px 15px;
            border-bottom: 1px dashed #e5e7eb;
            color: #4b5563;
        }

        .totals-section {
            margin-top: 10px;
        }

        .totals-table {
            width: 250px;
            float: right;
        }

        .totals-table td {
            padding: 8px 15px;
            text-align: right;
        }

        .totals-table .label {
            text-align: left;
            color: #4b5563;
            font-size: 13px;
        }

        .totals-table .value {
            font-weight: bold;
            color: #111827;
            font-size: 13px;
        }

        .totals-table .total-label {
            text-align: left;
            font-size: 15px;
            font-weight: bold;
            color: #111827;
            padding-top: 15px;
        }

        .totals-table .total-value {
            font-size: 15px;
            font-weight: bold;
            color: #111827;
            padding-top: 15px;
        }

        .payment-details {
            margin-top: 40px;
            clear: both;
        }

        .payment-details .title {
            color: #6b7280;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }

        .payment-details p {
            margin: 4px 0;
            color: #6b7280;
            font-size: 12px;
        }

        .payment-details strong {
            color: #6b7280;
            font-weight: normal;
        }

        .payment-details .val {
            color: #6b7280;
            font-weight: bold;
        }

        .notes-box {
            margin-top: 30px;
            background-color: #e0f2fe;
            border: 1px solid #bae6fd;
            padding: 15px;
            border-radius: 4px;
            font-size: 11px;
            color: #0369a1;
            line-height: 1.6;
        }

        .notes-box strong {
            color: #0369a1;
            margin-right: 5px;
        }

        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }

        .text-end {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <table class="header-section">
        <tr>
            <td>
                @php
                    $urlPath      = parse_url($business_info['business_logo_path'], PHP_URL_PATH);
                    $relativePath = ltrim($urlPath, '/');
                    $subPath      = str_replace('storage/', '', $relativePath);
                    $publicPath = public_path($relativePath);
                    $storagePath = storage_path('app/public/' . $subPath);
                    $diskPath = file_exists($publicPath) ? $publicPath : $storagePath;
                    if (file_exists($diskPath)) {
                        $ext  = strtolower(pathinfo($diskPath, PATHINFO_EXTENSION));
                        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                                 'png' => 'image/png',   'gif' => 'image/gif',
                                 'webp'=> 'image/webp',  'svg' => 'image/svg+xml'][$ext] ?? 'image/png';
                        $logoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($diskPath));
                    } else {
                        $logoSrc = $business_info['business_logo_path'];
                    }
                @endphp
                <img src="{{ $logoSrc }}" class="logo" alt="{{ $business_info['company_name'] ?? 'Logo' }}">
            </td>
            <td class="company-info-text">
                <p><strong>Invoice No:</strong> #{{ $invoice->invoice_id }}</p>
            </td>
        </tr>
    </table>

    <!-- Addresses -->
    <div class="address-container">
        <table>
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <div class="address-box">
                        <div class="section-header">ADDRESS</div>
                        <div class="name">{{ $business_info['company_name'] }}</div>
                        <p>{{ $business_info['address_line_1'] }}</p>
                        <p>{{ $business_info['city'] }}, {{ $business_info['state'] }}
                            {{ $business_info['postal_code'] }}</p>
                        <p>{{ $business_info['phone_number'] }}</p>
                        <p>{{ $business_info['email'] }}</p>
                    </div>
                </td>
                <td style="width: 50%; vertical-align: top; text-align: right">
                    <div class="address-box">
                        <div class="section-header">BILLING ADDRESS</div>
                        <div class="name">
                            {{ $invoice->borrower ? $invoice->borrower->full_name : ($invoice->partner?->full_name ?? $invoice->partner?->company_name ?? $invoice->customer_name) }}
                        </div>
                        <p>
                            {{ $invoice->borrower
                                ? ($invoice->borrower->business_address_line_1 ?: $invoice->borrower->business_postal_code)
                                : ($invoice->partner ? ($invoice->partner->address_line_1 ?: $invoice->partner->postal_code) : 'Address not specified') }}
                        </p>
                        <p>Phone:
                            {{ $invoice->borrower ? $invoice->borrower->phone_number : ($invoice->partner?->phone_number ?? 'N/A') }}
                        </p>
                        <p>Email:
                            {{ $invoice->borrower ? $invoice->borrower->email : ($invoice->partner?->email ?? $invoice->email) }}
                        </p>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Summary Bar -->
    <table class="summary-bar">
        <tr>
            <td style="width: 25%;">
                <div class="label">INVOICE NO</div>
                <div class="value">#{{ $invoice->invoice_id }}</div>
            </td>
            <td style="width: 25%;">
                <div class="label">DATE</div>
                <div class="value">
                    {{ $invoice->payment_date ? $invoice->payment_date->format('d M Y') : $invoice->created_at->format('d M Y') }}
                </div>
            </td>
            <td style="width: 25%;">
                <div class="label">PAYMENT STATUS</div>
                <div class="value">
                    <span class="status-badge {{ strtolower($invoice->status) === 'paid' ? 'status-paid' : '' }}">
                        {{ $invoice->status }}
                    </span>
                </div>
            </td>
            <td style="text-align: right;">
                <div class="label">TOTAL AMOUNT</div>
                <div class="value">${{ number_format($invoice->payment_amount, 2) }}</div>
            </td>
        </tr>
    </table>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="text-align: left;">Enrollment Details</th>
                <th style="text-align: right; width: 120px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @php $items = $invoice->details['items'] ?? [['description' => $invoice->subscription->enrollment_type ?? 'Platform Service Enrollment', 'amount' => $invoice->payment_amount]]; @endphp
            @foreach ($items as $item)
                <tr>
                    <td>
                        <div style="font-weight: bold; color: #374151;">{{ $item['description'] }}</div>
                    </td>
                    <td style="text-align: right; font-weight: bold;">${{ number_format($item['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals-section clearfix">
        <table class="totals-table">
            <tr>
                <td class="label">Sub Total:</td>
                <td class="value">${{ number_format($invoice->payment_amount, 2) }}</td>
            </tr>
            <tr>
                <td class="total-label">Total Amount:</td>
                <td class="total-value">${{ number_format($invoice->payment_amount, 2) }}</td>
            </tr>
        </table>
    </div>

    <!-- Payment Details -->
    <div class="payment-details">
        <div class="title">Payment Details:</div>
        <p><strong>Payment Method:</strong> <span
                class="val">{{ $invoice->details['payment_method'] ?? 'Stripe' }}</span></p>
        <p><strong>Payment Status:</strong> <span class="val">{{ $invoice->status }}</span></p>
        <p><strong>Card Holder:</strong> <span
                class="val">{{ $invoice->borrower ? $invoice->borrower->full_name : ($invoice->partner?->full_name ?? $invoice->partner?->company_name ?? $invoice->customer_name) }}</span>
        </p>
        @if (!empty($invoice->details['card_last4']))
            <p><strong>Card Number:</strong> <span class="val">xxxx xxxx xxxx {{ $invoice->details['card_last4'] }}</span>
            </p>
        @endif
        <p><strong>Total Amount:</strong> <span class="val">${{ number_format($invoice->payment_amount, 2) }}</span></p>
    </div>

    <!-- Notes -->
    <div class="notes-box">
        <strong>NOTES:</strong>
        All accounts are to be paid within 7 days from receipt of invoice. To be paid by Stripe. If account is not paid
        within 7 days the credits details supplied as confirmation of work undertaken will be charged the agreed quoted
        fee noted above.
    </div>
</body>

</html>
