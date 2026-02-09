@extends('emails.layouts.mail')

@section('title', $content['subject'] ?? 'Notification')

@section('content')
    <div class="appointment-title mt-3">{{ $content['subject'] ?? 'Notification' }}</div>

    @if (isset($content['greeting']))
        <h2>{{ $content['greeting'] }}</h2>
    @endif

    <div class="description-card">
        @if (isset($content['lines']) && is_array($content['lines']))
            @foreach ($content['lines'] as $line)
                <p>{{ $line }}</p>
            @endforeach
        @endif
    </div>

    @if (isset($data['code']))
        <div class="box-primary">
            <div class="temp-password" style="font-size: 24px; padding: 10px 20px;">{{ $data['code'] }}</div>
        </div>
    @endif

    @if (isset($content['action']) && $content['action'])
        <div class="btn-container">
            <a href="{{ $content['action']['url'] }}" class="btn">
                {{ $content['action']['text'] }}
            </a>
        </div>
    @endif

    @if (isset($content['warning']))
        <div class="alert alert-danger">
            <strong>⚠️ Warning:</strong> {{ $content['warning'] }}
        </div>
    @endif

    @if (isset($content['info']))
        <div class="box-note">
            <strong>ℹ️ Info:</strong> {{ $content['info'] }}
        </div>
    @endif

    <p style="margin-top: 30px; font-size: 12px; color: #878a99;">
        This email was sent to {{ $user->email }}. If you did not expect this email, please contact our support team.
    </p>
@endsection

@section('footer')
    @if (isset($content['salutation']))
        <div class="salutation" style="margin-bottom: 20px;">{!! $content['salutation'] !!}</div>
    @endif
    @parent
@endsection
