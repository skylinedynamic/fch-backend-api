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
            padding: 20px;
        }

        .logo {
            width: 140px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-section td {
            vertical-align: top;
        }

        .company-info-text {
            text-align: right;
            font-size: 12px;
            color: #374151;
        }

        .company-info-text p {
            margin: 2px 0;
        }

        .company-info-text strong {
            color: #111827;
        }

        .address-container {
            margin-top: 30px;
            margin-bottom: 30px;
        }

        .section-header {
            color: #9ca3af;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .address-box {
            margin-bottom: 20px;
        }

        .address-box p {
            margin: 2px 0;
            color: #4b5563;
        }

        .address-box .name {
            font-weight: bold;
            font-size: 14px;
            color: #111827;
            margin-bottom: 4px;
        }

        .summary-bar {
            border-top: 1px solid #f3f4f6;
            border-bottom: 1px solid #f3f4f6;
            margin-bottom: 30px;
            padding: 10px 0;
        }

        .summary-bar td {
            padding: 10px 15px;
            vertical-align: top;
        }

        .summary-bar .label {
            color: #9ca3af;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .summary-bar .value {
            font-weight: bold;
            color: #111827;
            font-size: 13px;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
        }

        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-unpaid {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .items-table thead th {
            background-color: #f9fafb;
            text-align: left;
            padding: 12px 15px;
            font-size: 11px;
            text-transform: uppercase;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }

        .items-table td {
            padding: 12px 15px;
            border-bottom: 1px dotted #e5e7eb;
            color: #4b5563;
        }

        .totals-section {
            margin-top: 30px;
        }

        .totals-table {
            width: 250px;
            float: right;
        }

        .totals-table td {
            padding: 6px 15px;
            text-align: right;
        }

        .totals-table .label {
            text-align: left;
            color: #6b7280;
        }

        .totals-table .value {
            font-weight: bold;
            color: #111827;
        }

        .totals-table .total-label {
            text-align: left;
            font-size: 16px;
            font-weight: bold;
            color: #1e3a8a;
            padding-top: 15px;
        }

        .totals-table .total-value {
            font-size: 16px;
            font-weight: bold;
            color: #1e3a8a;
            padding-top: 15px;
        }

        .payment-details {
            margin-top: 60px;
            clear: both;
        }

        .payment-details .title {
            color: #9ca3af;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .payment-details p {
            margin: 3px 0;
            color: #4b5563;
        }

        .payment-details strong {
            color: #374151;
        }

        .notes-box {
            margin-top: 40px;
            background-color: #eff6ff;
            border: 1px solid #dbeafe;
            padding: 15px;
            border-radius: 6px;
            font-size: 11px;
            color: #1e40af;
            line-height: 1.5;
        }

        .notes-box strong {
            color: #1e3a8a;
            display: block;
            margin-bottom: 4px;
        }

        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <table class="header-section">
        <tr>
            <td>
                <img src="{{ $business_info['business_logo_path'] }}" class="logo" alt="Logo">
                <div style="font-size: 12px; font-weight: bold; margin-top: 10px;">{{ $business_info['company_name'] }}
                </div>
            </td>
            <td class="company-info-text">
                <p><strong>Invoice No:</strong> #{{ $invoice->invoice_id }}</p>
                <p><strong>Email:</strong> {{ $business_info['email'] }}</p>
                <p><strong>Website:</strong> {{ $business_info['website'] }}</p>
                <p><strong>Contact No:</strong> {{ $business_info['phone_number'] }}</p>
            </td>
        </tr>
    </table>

    <!-- Addresses -->
    <div class="address-container">
        <table>
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <div class="address-box">
                        <div class="section-header">Address</div>
                        <p>{{ $business_info['address_line_1'] }}</p>
                        <p>Zip-code: {{ $business_info['postal_code'] }}</p>
                    </div>
                </td>
                <td style="width: 50%; vertical-align: top; text-align: right">
                    <div class="address-box">
                        <div class="section-header">Billing Address</div>
                        <div class="name">
                            {{ $invoice->details['billing_address']['name'] ?? $invoice->customer_name }}</div>
                        <p>{{ $invoice->details['billing_address']['street'] ?? 'Address not specified' }}</p>
                        <p>Phone: {{ $invoice->details['billing_address']['phone'] ?? 'N/A' }}</p>
                        <p>Country: {{ $invoice->details['billing_address']['country'] ?? 'USA' }}</p>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Summary Bar -->
    <table class="summary-bar">
        <tr>
            <td style="width: 25%;">
                <div class="label">Invoice No</div>
                <div class="value">#{{ $invoice->invoice_id }}</div>
            </td>
            <td style="width: 20%;">
                <div class="label">Date</div>
                <div class="value">
                    {{ $invoice->payment_date ? $invoice->payment_date->format('d M Y') : $invoice->created_at->format('d M Y') }}
                </div>
            </td>
            <td style="width: 25%;">
                <div class="label">Payment Status</div>
                <div class="value">
                    <span
                        class="status-badge {{ strtolower($invoice->status) === 'paid' ? 'status-paid' : 'status-unpaid' }}">
                        {{ $invoice->status }}
                    </span>
                </div>
            </td>
            <td style="text-align: right;">
                <div class="label">Total Amount</div>
                <div class="value">${{ number_format($invoice->payment_amount, 2) }}</div>
            </td>
        </tr>
    </table>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px; text-align: center;">#</th>
                <th>Product Details</th>
                <th style="text-align: right; width: 100px;">Rate</th>
                <th style="text-align: center; width: 80px;">Quantity</th>
                <th style="text-align: right; width: 100px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @php $items = $invoice->details['items'] ?? [['description' => 'Platform Service Enrollment', 'rate' => $invoice->payment_amount, 'quantity' => 1, 'amount' => $invoice->payment_amount]]; @endphp
            @foreach ($items as $index => $item)
                <tr>
                    <td style="text-align: center; color: #9ca3af;">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </td>
                    <td>
                        <div style="font-weight: bold; color: #374151;">{{ $item['description'] }}</div>
                    </td>
                    <td style="text-align: right;">${{ number_format($item['rate'], 2) }}</td>
                    <td style="text-align: center;">{{ str_pad($item['quantity'] ?? 1, 2, '0', STR_PAD_LEFT) }}</td>
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
        <p><strong>Payment Method:</strong> {{ $invoice->details['payment_method'] ?? 'Stripe / Card' }}</p>
        <p><strong>Card Holder:</strong> {{ $invoice->details['billing_address']['name'] ?? $invoice->customer_name }}
        </p>
        @if (!empty($invoice->details['card_last4']))
            <p><strong>Card Number:</strong> xxxx xxxx xxxx {{ $invoice->details['card_last4'] }}</p>
        @endif
        <p><strong>Total Amount:</strong> ${{ number_format($invoice->payment_amount, 2) }}</p>
    </div>

    <!-- Notes -->
    <div class="notes-box">
        <strong>NOTES:</strong>
        All accounts are to be paid within 7 days from receipt of invoice. To be paid by cheque or credit card or direct
        payment online. If account is not paid within 7 days the credits details supplied as confirmation of work
        undertaken will be charged the agreed quoted fee noted above.
    </div>
</body>

</html>
