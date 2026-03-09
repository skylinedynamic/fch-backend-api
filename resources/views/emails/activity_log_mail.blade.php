@extends('emails.layouts.mail')

@section('title', $subject)

@section('content')

    <h4>Hello!</h4>
    <p>
        You have received a new activity log update. Please see the details below:
    </p>

    <div class="info-card-container box-light">
        <div class="info-card-header">
            ACTIVITY DETAILS
        </div>
        <div class="info-card-content">
            <table class="info-table">
                <tr>
                    <td class="label" style="width: 150px;">SUBJECT:</td>
                    <td style="text-align: left;"><strong>{{ $subject }}</strong></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="info-card-container box-light" style="margin-top: 20px;">
        <div class="info-card-header">
            ACTIVITY CONTENT
        </div>
        <div class="info-card-content" style="padding: 20px; color: #334155; line-height: 1.6;">
            {!! $body !!}
        </div>
    </div>

    @if($attachments_count > 0)
        <div class="info-card-container box-light" style="margin-top: 20px;">
            <div class="info-card-header">
                ATTACHMENTS ({{ $attachments_count }})
            </div>
            <div class="info-card-content" style="padding: 20px;">
                <ul style="margin: 0; padding-left: 20px; color: #4b5563;">
                    @foreach($attachment_names as $name)
                        <li style="margin-bottom: 5px;">{{ $name }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif


@endsection
