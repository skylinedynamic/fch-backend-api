<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoice->invoice_id }}</title>
    <style>
        body {
            font-family: 'Helvetica', sans-serif;
            color: #333;
            line-height: 1.5;
        }

        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }

        .logo {
            width: 150px;
        }

        .invoice-info {
            text-align: right;
        }

        .address-section {
            margin-bottom: 30px;
        }

        .billing-address {
            width: 50%;
            float: left;
        }

        .invoice-details {
            width: 50%;
            float: right;
        }

        .clear {
            clear: both;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th {
            background: #f8f9fa;
            text-align: left;
            padding: 12px;
            border-bottom: 2px solid #dee2e6;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }

        .totals {
            margin-top: 20px;
            text-align: right;
        }

        .footer-note {
            margin-top: 50px;
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            font-size: 12px;
        }

        .status {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 12px;
        }

        .status-paid {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-unpaid {
            background: #fff3e0;
            color: #ef6c00;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="logo">
            <h2 style="color: #1a237e;">FOUR CORNER</h2>
            <p style="font-size: 10px; margin-top: -10px;">HOLDINGS, LLC</p>
        </div>
        <div class="invoice-info">
            <p><strong>Invoice No:</strong> #{{ $invoice->invoice_id }}</p>
            <p><strong>Email:</strong> support@company.com</p>
            <p><strong>Website:</strong> www.company.com</p>
        </div>
    </div>

    <div class="address-section">
        <div class="billing-address">
            <h4 style="color: #666; margin-bottom: 10px;">BILLING ADDRESS</h4>
            <p><strong>{{ $invoice->details['billing_address']['name'] ?? $invoice->customer_name }}</strong></p>
            <p>{{ $invoice->details['billing_address']['street'] ?? '' }}</p>
            <p>{{ $invoice->details['billing_address']['city'] ?? '' }}, {{ $invoice->details['billing_address']['state'] ?? '' }} {{ $invoice->details['billing_address']['zip'] ?? '' }}</p>
        </div>
        <div class="invoice-details">
            <table style="margin-top: 0;">
                <tr>
                    <td><strong>INVOICE NO</strong></td>
                    <td><strong>DATE</strong></td>
                    <td><strong>STATUS</strong></td>
                </tr>
                <tr>
                    <td>#{{ $invoice->invoice_id }}</td>
                    <td>{{ $invoice->payment_date ? $invoice->payment_date->format('d M, Y') : '-' }}</td>
                    <td><span class="status status-{{ strtolower($invoice->status) }}">{{ $invoice->status }}</span></td>
                </tr>
            </table>
        </div>
        <div class="clear"></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Product Details</th>
                <th>Rate</th>
                <th>Quantity</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->details['items'] ?? [] as $index => $item)
            <tr>
                <td>{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</td>
                <td>{{ $item['description'] }}</td>
                <td>${{ number_format($item['rate'], 2) }}</td>
                <td>{{ str_pad($item['quantity'], 2, '0', STR_PAD_LEFT) }}</td>
                <td>${{ number_format($item['amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <p><strong>Sub Total:</strong> ${{ number_format($invoice->payment_amount, 2) }}</p>
        <p><strong>Estimated Tax (0%):</strong> $0.00</p>
        <h3 style="color: #1a237e;">Total Amount: ${{ number_format($invoice->payment_amount, 2) }}</h3>
    </div>

    <div class="footer-note">
        <strong>NOTES:</strong> All accounts are to be paid within 7 days from receipt of invoice. To be paid by cheque or credit card or direct payment online.
    </div>
</body>

</html>