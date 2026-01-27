<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Welcome to {{ $business_info['company_name'] }}</title>
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
            /* Elegant deep blue */
            color: #ffffff;
            padding: 28px 25px;
            text-align: center;
        }

        .header img {
            height: 120px;
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
            /* same as header for brand consistency */
            padding: 25px 20px;
            text-align: center;
            font-size: 14px;
            color: #ffffff;
        }

        .footer img {
            height: 100px;
            margin-bottom: 10px;
            filter: brightness(0) invert(1);
            /* makes logo visible on dark bg */
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
            {{-- Local: embed from filesystem --}}
            <img src="{{ $message->embed($business_info['business_logo_path']) }}"
                alt="{{ $business_info['company_name'] }} Logo">
            @else
            {{-- Production: use public URL --}}
            <img src="{{ $business_info['business_logo_url'] }}"
                alt="{{ $business_info['company_name'] }} Logo">
            @endif
            <h1>{{ $business_info['company_name'] }}</h1>
        </div>

        <div class="content">
            <h2>Hello {{ $user->name }}!</h2>
            <p>
                Welcome to <strong>{{ $business_info['company_name'] }}</strong>.
                @if(!empty($is_update))
                Your borrower account has been successfully updated. Below are your new login details:
                @else
                Your borrower account has been successfully created. Below are your login details:
                @endif
            </p>

            <table class="details-table">
                <tr>
                    <td>Full Name</td>
                    <td>{{ $user->name }}</td>
                </tr>
                <tr>
                    <td>Email</td>
                    <td><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></td>
                </tr>
                @if(!empty($user->phone_number))
                <tr>
                    <td>Phone</td>
                    <td>{{ $user->phone_number }}</td>
                </tr>
                @endif
                <tr>
                    <td>Temporary Password</td>
                    <td><strong>{{ $plainPassword }}</strong></td>
                </tr>
            </table>

            <div style="text-align:center;">
                @if(!empty($paymentLink))
                <p style="margin-bottom: 20px;">Please complete your payment of <strong>${{ number_format($amount, 2) }}</strong> to finalize your account setup.</p>
                <a href="{{ $paymentLink }}" class="btn">Pay ${{ number_format($amount, 2) }} Now</a>
                @else
                <a href="{{ $loginUrl }}" class="btn">Login to Your Account</a>
                @endif
            </div>
        </div>

        <div class="footer">
            @if(app()->environment('local'))
            {{-- Local: embed from filesystem --}}
            <img src="{{ $message->embed($business_info['business_logo_path']) }}"
                alt="{{ $business_info['company_name'] }} Logo">
            @else
            {{-- Production: use public URL --}}
            <img src="{{ $business_info['business_logo_url'] }}"
                alt="{{ $business_info['company_name'] }} Logo">
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