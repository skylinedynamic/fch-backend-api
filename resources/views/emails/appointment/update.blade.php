<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Appointment Schedule Change</title>
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
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        }
        .header {
            background: #1e3a8a; /* Elegant deep blue */
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
        }
        small {
            color: #cbd5e1;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            background-color: #e0f2fe;
            color: #0369a1;
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
        <h2>Hello {{ $assigneeName }},</h2>
        <p>
            The schedule for an appointment has been <strong>changed</strong>. Please verify the new details below:
        </p>

        <table class="details-table">
            <tr>
                <td>Subject</td>
                <td>{{ $appointment->subject }}</td>
            </tr>
            <tr>
                <td>Appointment Code</td>
                <td><span class="badge">{{ $appointment->appointment_code }}</span></td>
            </tr>
            <tr>
                <td>New Date & Time</td>
                <td>
                    {{ \Carbon\Carbon::parse($appointment->appointment_date)->format('F j, Y, g:i a') }}
                    @if($appointment->timezone)
                        <br><small>({{ $appointment->timezone }})</small>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Duration</td>
                <td>{{ $appointment->duration_minutes }} minutes</td>
            </tr>
            <tr>
                <td>Meeting Type</td>
                <td>{{ $appointment->meeting_type }}</td>
            </tr>
            @if($appointment->location)
            <tr>
                <td>Location</td>
                <td>{{ $appointment->location }}</td>
            </tr>
            @endif
            @if($appointment->description)
            <tr>
                <td>Description</td>
                <td>{{ $appointment->description }}</td>
            </tr>
            @endif
        </table>
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
            {{ $business_info['company_name'] }} | {{ $business_info['phone_number'] }} | 
            <a href="mailto:{{ $business_info['email'] }}" style="color:#e5e7eb;">{{ $business_info['email'] }}</a><br>
            {{ $business_info['address_line_1'] }}
        </p>
        <small>© {{ date('Y') }} {{ $business_info['company_name'] }}. All rights reserved.</small>
    </div>
</div>
</body>
</html>
