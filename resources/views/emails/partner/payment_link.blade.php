<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Finalize Your Partner Enrollment - {{ $business_info['company_name'] }}</title>
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

        .btn {
            display: inline-block;
            background: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 600;
            margin-top: 25px;
            font-size: 15px;
            transition: background 0.2s ease;
        }

        .btn:hover {
            background: #1e40af;
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
            <img src="{{ $message->embed($business_info['business_logo_path']) }}"
                alt="{{ $business_info['company_name'] }} Logo">
            @else
            <img src="{{ $business_info['business_logo_url'] }}"
                alt="{{ $business_info['company_name'] }} Logo">
            @endif
            <h1>{{ $business_info['company_name'] }}</h1>
        </div>

        <div class="content">
            <h2>Hello {{ $user->name }},</h2>
            <p>
                Welcome to <strong>{{ $business_info['company_name'] }}</strong>.
            </p>
            <p>
                Your partner account has been successfully created. To finalize your enrollment and activate your access to the <strong>{{ $business_info['company_name'] }} Partner Portal</strong>, please complete the payment below.
            </p>

            <h3 style="color: #111827; font-size: 18px; margin-top: 25px; margin-bottom: 10px;">Once activated, you can:</h3>
            <ul style="color: #4b5563; font-size: 15px; margin-bottom: 25px; padding-left: 20px;">
                <li style="margin-bottom: 8px;">Log in to your Partner Dashboard</li>
                <li style="margin-bottom: 8px;">Add and manage clients</li>
                <li style="margin-bottom: 8px;">Track client progress through tiers and underwriting</li>
                <li style="margin-bottom: 8px;">View commission activity (as applicable)</li>
                <li style="margin-bottom: 0;">Access partner training and resources</li>
            </ul>

            <table class="details-table">
                <tr>
                    <td>Enrollment Type</td>
                    <td><strong>{{ $enrollmentType }}</strong></td>
                </tr>
                <tr>
                    <td>Payment Amount</td>
                    <td><strong>${{ number_format($amount, 2) }}</strong></td>
                </tr>
                @if(!empty($plainPassword))
                <tr>
                    <td>Temporary Password</td>
                    <td><strong>{{ $plainPassword }}</strong></td>
                </tr>
                @endif
            </table>

            <div style="text-align:center; margin-top: 30px; margin-bottom: 25px;">
                <p>Click below to complete your payment:</p>
                <a href="{{ $paymentLink }}" class="btn">Pay Now & Complete Enrollment</a>
            </div>

            <div style="border-top: 1px solid #e5e7eb; padding-top: 20px; color: #4b5563; font-size: 14px;">
                <p><strong>Please note:</strong></p>
                <p>
                    Partner access is role-based and read-only for underwriting decisions. All underwriting, tier advancement, and funding decisions remain managed by the <strong>{{ $business_info['company_name'] }}</strong> internal team.
                </p>
            </div>

            <p style="margin-top: 30px;">
                We’re excited to have you onboard.
            </p>
        </div>

        <div class="footer">
            @if(app()->environment('local'))
            <img src="{{ $message->embed($business_info['business_logo_path']) }}"
                alt="{{ $business_info['company_name'] }} Logo">
            @else
            <img src="{{ $business_info['business_logo_url'] }}"
                alt="{{ $business_info['company_name'] }} Logo">
            @endif
            <p>
                <strong>{{ $business_info['company_name'] }}</strong><br>
                Partner Operations Team<br>
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