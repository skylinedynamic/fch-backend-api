<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $content['subject'] ?? 'Notification' }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 10px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #495057;
        }
        .content {
            margin-bottom: 30px;
        }
        .content p {
            margin-bottom: 15px;
            line-height: 1.6;
        }
        .code {
            background-color: #f8f9fa;
            border: 2px solid #007bff;
            border-radius: 6px;
            padding: 15px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            letter-spacing: 3px;
            color: #007bff;
            margin: 20px 0;
        }
        .action-button {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
        }
        .action-button:hover {
            background-color: #0056b3;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            font-size: 14px;
            color: #6c757d;
        }
        .salutation {
            margin-top: 20px;
            font-weight: 600;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
            color: #856404;
        }
        .info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
            color: #0c5460;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ config('app.name') }}</div>
        </div>

        @if(isset($content['greeting']))
            <div class="greeting">{{ $content['greeting'] }}</div>
        @endif

        <div class="content">
            @if(isset($content['lines']) && is_array($content['lines']))
                @foreach($content['lines'] as $line)
                    <p>{{ $line }}</p>
                @endforeach
            @endif

            @if(isset($data['code']))
                <div class="code">{{ $data['code'] }}</div>
            @endif

            @if(isset($content['action']) && $content['action'])
                <div style="text-align: center;">
                    <a href="{{ $content['action']['url'] }}" class="action-button">
                        {{ $content['action']['text'] }}
                    </a>
                </div>
            @endif

            @if(isset($content['warning']))
                <div class="warning">
                    <strong>⚠️ Warning:</strong> {{ $content['warning'] }}
                </div>
            @endif

            @if(isset($content['info']))
                <div class="info">
                    <strong>ℹ️ Info:</strong> {{ $content['info'] }}
                </div>
            @endif
        </div>

        <div class="footer">
            @if(isset($content['salutation']))
                <div class="salutation">{!! $content['salutation'] !!}</div>
            @endif

            <p style="margin-top: 20px; font-size: 12px; color: #adb5bd;">
                This email was sent to {{ $user->email }}. If you did not expect this email, please contact our support team.
            </p>
        </div>
    </div>
</body>
</html>