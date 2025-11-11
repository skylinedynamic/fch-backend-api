<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Demo Request from {{ $demoRequest->customer_name }}</title>
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
    </style>
</head>
<body>
<div class="container">
    <div class="header">
       
        <h1>New Demo Request Received</h1>
    </div>

    <div class="content">
        <h2>Hello {{ $assignedUser->name ?? 'Team' }},</h2>
        <p>
            A new demo request has been submitted by <strong>{{ $demoRequest->customer_name }}</strong>.
            Below are the request details:
        </p>

        <table class="details-table">
            <tr>
                <td>Customer Name</td>
                <td>{{ $demoRequest->customer_name }}</td>
            </tr>
            <tr>
                <td>Full Name</td>
                <td>{{ $demoRequest->full_name }}</td>
            </tr>
            <tr>
                <td>Phone</td>
                <td>{{ $demoRequest->phone }}</td>
            </tr>
            <tr>
                <td>Email</td>
                <td><a href="mailto:{{ $demoRequest->email }}">{{ $demoRequest->email }}</a></td>
            </tr>
            @if(!empty($demoRequest->country))
            <tr>
                <td>Country</td>
                <td>{{ $demoRequest->country }}</td>
            </tr>
            @endif
            @if(!empty($demoRequest->website))
            <tr>
                <td>Website</td>
                <td><a href="{{ $demoRequest->website }}" target="_blank">{{ $demoRequest->website }}</a></td>
            </tr>
            @endif
            <tr>
                <td>Demo Start Date</td>
                <td>{{ $demoRequest->demo_start ?? '—' }}</td>
            </tr>
            <tr>
                <td>Demo End Date</td>
                <td>{{ $demoRequest->demo_end ?? '—' }}</td>
            </tr>
            <tr>
                <td>Status</td>
                <td><strong style="text-transform:capitalize;">{{ $demoRequest->status }}</strong></td>
            </tr>
            
        </table>

        <div style="text-align:center;">
            <a href="{{ url('/') }}" class="btn">View in Dashboard</a>
        </div>
    </div>

    
</div>
</body>
</html>
