@extends('emails.layouts.mail')

@section('title', $subject)

@section('content')

    <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello!</h4>
    <p style="line-height: 1.5; margin-bottom: 16px;">
        You have received a new activity log update. Please see the details below:
    </p>

    <div class="info-card-container box-light" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header" style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            ACTIVITY DETAILS
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 150px; padding-left: 10px;">SUBJECT:</td>
                    <td style="padding: 10px 0; border-bottom: none; text-align: left; font-size: 13px; font-weight: 500; color: #0f172a; padding-right: 10px;"><strong>{{ $subject }}</strong></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="info-card-container box-light" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden; margin-top: 20px;">
        <div class="info-card-header" style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            ACTIVITY CONTENT
        </div>
        <div class="info-card-content" style="padding: 20px; color: #334155; line-height: 1.6; font-size: 14px;">
            {!! $body !!}
        </div>
    </div>

    @if($attachments_count > 0)
        <div class="info-card-container box-light" style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden; margin-top: 20px;">
            <div class="info-card-header" style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
                ATTACHMENTS ({{ $attachments_count }})
            </div>
            <div class="info-card-content" style="padding: 20px;">
                <ul style="margin: 0; padding-left: 20px; color: #4b5563; font-size: 14px;">
                    @foreach($attachment_names as $name)
                        <li style="margin-bottom: 5px;">{{ $name }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif


@endsection
