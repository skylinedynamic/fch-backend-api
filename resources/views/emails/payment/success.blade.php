<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Payment Receipt</title>
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
            padding: 20px 25px;
            text-align: center;
        }

        .header img {
            height: 60px;
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
            padding: 20px 20px;
            text-align: center;
            font-size: 14px;
            color: #ffffff;
        }

        .footer img {
            height: 45px;
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
            <h1>Payment Receipt</h1>
        </div>

        <div class="content">
            <h2>Hello {{ $first_name }},</h2>
            <p>
                This is a confirmation that your payment was successfully processed.
            </p>

            <h3 style="color: #111827; font-size: 18px; margin-top: 25px; margin-bottom: 10px;">Payment Details</h3>
            <ul style="list-style: none; padding: 0; color: #4b5563; font-size: 15px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; background: #f9fafb;">
                <li style="margin-bottom: 8px;"><strong>• Amount:</strong> {{ strtoupper($currency) }} {{ number_format($amount, 2) }}</li>
                <li style="margin-bottom: 8px;"><strong>• Date:</strong> {{ $date }}</li>
                <li style="margin-bottom: 8px;"><strong>• Transaction ID:</strong> {{ $transaction_id }}</li>
                <li style="margin-bottom: 0;"><strong>• Description:</strong> {{ $description }}</li>
            </ul>

            <p style="margin-top: 25px;">
                Your account has been updated, and no further action is required at this time.
            </p>
            <p>
                If this payment is related to your <strong>{{ $business_info['company_name'] }}</strong> program enrollment or activation, our system will continue progressing your file automatically based on underwriting and tier requirements.
            </p>
            <p>
                If you have any questions or believe this payment was made in error, please contact our support team through your portal.
            </p>
            <p style="margin-top: 30px;">
                Thank you for your business.
            </p>
        </div>

        <div class="footer">
            @if(app()->environment('local'))
            <img src="{{ $message->embed($business_info['business_logo_path']) }}" alt="{{ $business_info['company_name'] }} Logo">
            @else
            <img src="{{ $business_info['business_logo_url'] }}" alt="{{ $business_info['company_name'] }} Logo">
            @endif
            <p>
                <strong>{{ $business_info['company_name'] }}</strong><br>
                Business Credit & Funding Platform<br>
                {{ $business_info['phone_number'] }} | <a href="mailto:{{ $business_info['email'] }}" style="color: #ffffff;">{{ $business_info['email'] }}</a>
            </p>
            <p style="font-size: 12px; margin-top: 10px;">
                {{ $business_info['address_line_1'] }}
            </p>
            <small>© {{ date('Y') }} {{ $business_info['company_name'] }}. All rights reserved.</small>
        </div>
    </div>
</body>

</html>