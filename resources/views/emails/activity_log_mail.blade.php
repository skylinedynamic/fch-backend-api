@extends('emails.layouts.mail')

@section('title', $subject)

{{--
    New Email from an activity, in the same style as the other emails
    (activity_log/task_assigned): the greeting, one line naming the sender, then
    the gray card holding the subject and the message as it was written, and
    the attached files. Inline styles only - mail clients drop most <style>.
--}}
@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">{{ !empty($recipientName) ? __('activity_log.mail.greeting_name', ['name' => $recipientName]) : __('activity_log.mail.greeting') }}</h4>

    <p style="line-height: 1.5; margin-bottom: 20px;">{{ __('activity_log.mail.intro', ['sender' => $siteName]) }}</p>

    <div class="activity-mail__card" style="background-color: #f8fafc; border-radius: 4px; padding: 20px; margin-bottom: 24px; text-align: left;">
        <p class="activity-mail__subject" style="font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 16px 0;">{{ $subject }}</p>

        <p style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 6px 0;">{{ __('activity_log.mail.message') }}</p>
        <div class="activity-mail__body" style="font-size: 14px; color: #334155; line-height: 1.6;">
            {!! $body !!}
        </div>

        @if($attachments_count > 0)
            <div class="activity-mail__attachments" style="margin-top: 20px;">
                <p style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 8px 0;">{{ __('activity_log.mail.attachments', ['count' => $attachments_count]) }}</p>
                <table style="width: 100%; border-collapse: collapse;">
                    @foreach($attachment_names as $name)
                        <tr>
                            <td style="padding: 6px 0; vertical-align: middle;">
                                <table style="border-collapse: collapse;">
                                    <tr>
                                        <td style="width: 32px; height: 32px; border-radius: 4px; background-color: #eceef6; color: #405189; font-size: 10px; font-weight: 600; text-align: center; text-transform: uppercase; vertical-align: middle;">{{ pathinfo($name, PATHINFO_EXTENSION) ?: __('activity_log.mail.file') }}</td>
                                        <td style="padding-left: 10px; font-size: 14px; font-weight: 500; color: #0f172a; vertical-align: middle; word-break: break-word;">{{ $name }}</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endforeach
                </table>
                <p style="font-size: 12px; color: #64748b; margin: 8px 0 0 0;">{{ __('activity_log.mail.attachments_note') }}</p>
            </div>
        @endif
    </div>
@endsection
