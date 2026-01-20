<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>New Payment Received</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background-color: #eef2f7;
            margin: 0;
            padding: 0;
            color: #333;
        }

        .container {
            max-width: 650px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        .header {
            background: #1e3a8a;
            color: #ffffff;
            padding: 28px 25px;
            text-align: center;
        }

        .header img {
            height: 42px;
            margin-bottom: 10px;
        }

        .header h1 {
            font-size: 21px;
            font-weight: 600;
            margin: 0;
            letter-spacing: 0.3px;
        }

        .content {
            padding: 35px 30px;
        }

        .content h2 {
            color: #111827;
            font-size: 20px;
            margin-bottom: 12px;
        }

        .content p {
            color: #4b5563;
            line-height: 1.6;
            font-size: 15px;
            margin-top: 0;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
            border-radius: 8px;
            overflow: hidden;
        }

        .details-table td {
            border: 1px solid #e5e7eb;
            padding: 12px 16px;
            font-size: 15px;
        }

        .details-table td:first-child {
            font-weight: 600;
            background: #f9fafb;
            width: 35%;
            color: #1f2937;
        }

        .footer {
            background: #1e3a8a;
            padding: 25px 20px;
            text-align: center;
            font-size: 14px;
            color: #ffffff;
        }

        .footer img {
            height: 35px;
            margin-bottom: 10px;
            filter: brightness(0) invert(1);
        }

        .footer p {
            margin: 5px 0;
            color: #e5e7eb;
            line-height: 1.5;
            font-size: 13px;
        }

        .footer a {
            color: #ffffff;
            text-decoration: none;
        }

        small {
            color: #cbd5e1;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            @if(app()->environment('local'))
            <img src="{{ $message->embed($business_info['business_logo_path']) }}" alt="{{ $business_info['company_name'] }} Logo">
            @else
            <img src="{{ $business_info['business_logo_url'] }}" alt="{{ $business_info['company_name'] }} Logo">
            @endif
            <h1>New Payment Received</h1>
        </div>

        <div class="content">
            <h2>Payment Notification</h2>
            <p>
                A new payment has been successfully processed on <strong>{{ $business_info['company_name'] }}</strong>.
            </p>

            <table class="details-table">
                <tr>
                    <td>Payer Name</td>
                    <td>{{ $user_name }}</td>
                </tr>
                <tr>
                    <td>Payer Email</td>
                    <td><a href="mailto:{{ $user_email }}">{{ $user_email }}</a></td>
                </tr>
                <tr>
                    <td>Transaction ID</td>
                    <td>{{ $transaction_id }}</td>
                </tr>
                <tr>
                    <td>Date</td>
                    <td>{{ $date }}</td>
                </tr>
                <tr>
                    <td>Amount</td>
                    <td><strong>{{ strtoupper($currency) }} {{ number_format($amount, 2) }}</strong></td>
                </tr>
            </table>
        </div>

        <div class="footer">
            @if(app()->environment('local'))
            <img src="{{ $message->embed($business_info['business_logo_path']) }}" alt="{{ $business_info['company_name'] }} Logo">
            @else
            <img src="{{ $business_info['business_logo_url'] }}" alt="{{ $business_info['company_name'] }} Logo">
            @endif
            <p>
                {{ $business_info['company_name'] }} | {{ $business_info['phone_number'] }} |
                <a href="mailto:{{ $business_info['email'] }}">{{ $business_info['email'] }}</a><br>
                {{ $business_info['address_line_1'] }}
            </p>
            <small>© {{ date('Y') }} {{ $business_info['company_name'] }}. All rights reserved.</small>
        </div>
    </div>
</body>

</html>