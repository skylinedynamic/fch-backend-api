@extends('emails.layouts.mail')

@section('title', __('activity_log.task_assigned_mail_subject', ['subject' => $task['subject']]))

@section('content')
    <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">{{ __('activity_log.task_assigned_mail_heading') }}</h4>

    <p style="line-height: 1.5; margin-bottom: 20px;">
        {{ __('activity_log.task_assigned_mail_intro', ['assigner' => $task['assigner_name'], 'record' => $task['record']]) }}
    </p>

    <div style="background-color: #f8fafc; border-radius: 4px; padding: 20px; margin-bottom: 24px; text-align: left;">
        <p style="font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 16px 0;">{{ $task['subject'] }}</p>

        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 130px; vertical-align: middle;">{{ __('activity_log.task_assigned_assigned_to') }}</td>
                <td style="padding: 8px 0; vertical-align: middle;">
                    <table style="border-collapse: collapse;">
                        <tr>
                            <td style="width: 32px; height: 32px; border-radius: 4px; background-color: #eceef6; color: #405189; font-size: 12px; font-weight: 600; text-align: center; vertical-align: middle;">{{ $initials }}</td>
                            <td style="padding-left: 10px; font-size: 14px; font-weight: 500; color: #0f172a; vertical-align: middle;">{{ $task['assignee_name'] }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
            @if ($task['due_date'])
                <tr>
                    <td style="padding: 8px 0; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">{{ __('activity_log.task_assigned_due_date') }}</td>
                    <td style="padding: 8px 0; font-size: 14px; font-weight: 500; color: #0f172a;">{{ $task['due_date'] }}</td>
                </tr>
            @endif
            @if ($task['priority_label'])
                <tr>
                    <td style="padding: 8px 0; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">{{ __('activity_log.task_assigned_priority') }}</td>
                    <td style="padding: 8px 0;">
                        <span style="display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; color: {{ $priorityColours[0] }}; background-color: {{ $priorityColours[1] }};">{{ $task['priority_label'] }}</span>
                    </td>
                </tr>
            @endif
            @if ($task['status_label'])
                <tr>
                    <td style="padding: 8px 0; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">{{ __('activity_log.task_assigned_status') }}</td>
                    <td style="padding: 8px 0;">
                        <span style="display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; color: {{ $statusColours[0] }}; background-color: {{ $statusColours[1] }};">{{ $task['status_label'] }}</span>
                    </td>
                </tr>
            @endif
        </table>

        @if (!empty($task['description']))
            <p style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin: 16px 0 6px 0;">{{ __('activity_log.task_assigned_details') }}</p>
            <p style="font-size: 14px; color: #334155; line-height: 1.6; margin: 0;">{{ $task['description'] }}</p>
        @endif
    </div>

    @if ($taskUrl)
        <div class="btn-container" style="margin: 30px 0; text-align: center;">
            <a href="{{ $taskUrl }}" class="btn"
                style="display: inline-block; background-color: #405189; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">{{ __('activity_log.task_assigned_action') }}</a>
        </div>
    @endif
@endsection
