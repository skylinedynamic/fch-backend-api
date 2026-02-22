<!DOCTYPE html>
<html>

<head>
    <title>Cron Job Failure</title>
</head>

<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
        <h2 style="color: #d9534f;">⚠️ Cron Job Failure Notification</h2>
        <p>A scheduled task has failed on <strong>{{ config('app.name') }}</strong>.</p>

        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Job Name:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $data['job_name'] }}</td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Error Message:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee; color: #d9534f;">{{ $data['error_message'] }}</td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Timestamp:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $data['timestamp'] }}</td>
            </tr>
            @if(!empty($data['user_email']))
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">User Email:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $data['user_email'] }}</td>
            </tr>
            @endif
            @if(!empty($data['user_id']))
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">User ID:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $data['user_id'] }}</td>
            </tr>
            @endif
            @if(!empty($data['partner_name']))
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Partner Name:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $data['partner_name'] }}</td>
            </tr>
            @endif
            @if(isset($data['user_context']))
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">User Context:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{{ $data['user_context'] }}</td>
            </tr>
            @endif
        </table>

        <div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">
            <strong>Stack Trace Preview:</strong>
            <pre style="white-space: pre-wrap; word-wrap: break-word; font-size: 12px; margin-top: 5px;">{{ Str::limit($data['stack_trace'], 500) }}</pre>
        </div>

        <p style="font-size: 12px; color: #777; margin-top: 30px;">
            This is an automated notification from the server.
        </p>
    </div>
</body>

</html>