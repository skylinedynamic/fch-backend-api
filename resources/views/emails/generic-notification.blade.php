@extends('emails.layouts.mail')

@section('title', $content['subject'] ?? 'Notification')

@section('content')

    @if (isset($content['greeting']))
        <h4>{{ $content['greeting'] }}</h4>
    @endif

    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        @if (isset($content['lines']) && is_array($content['lines']))
            @foreach ($content['lines'] as $line)
                <p style="line-height: 1.5; margin-bottom: 15px;">{{ $line }}</p>
            @endforeach
        @endif
    </div>

    @if (isset($data['code']))
        <div class="box-primary" style="background-color: #f0f7ff; border: 1px solid #e0effe; border-radius: 8px; padding: 24px; margin-bottom: 24px; text-align: center;">
            <div class="temp-password" style="background: #ffffff; padding: 10px 20px; border: 1px solid #e2e8f0; border-radius: 6px; font-family: 'Courier New', Courier, monospace; font-weight: 600; color: #1e3a8a; display: inline-block; letter-spacing: 1px; font-size: 24px;">{{ $data['code'] }}</div>
        </div>
    @endif

    @if (isset($content['action']) && $content['action'])
        <div class="btn-container" style="margin: 30px 0; text-align: center;">
            <a href="{{ $content['action']['url'] }}" class="btn" style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">
                {{ $content['action']['text'] }}
            </a>
        </div>
    @endif

    @if (isset($content['warning']))
        <div class="alert alert-danger" style="padding: 15px 20px; margin-bottom: 20px; border: 1px solid #f5c2c7; border-radius: 4px; font-size: 14px; color: #842029; background-color: #f8d7da;">
            <strong style="color: inherit;">⚠️ Warning:</strong> {{ $content['warning'] }}
        </div>
    @endif

    @if (isset($content['info']))
        <div class="box-note" style="background-color: #f8fafc; border-left: 4px solid #cbd5e1; border-radius: 4px; padding: 16px 20px; margin-top: 32px; margin-bottom: 24px;">
            <strong style="color: #1e3a8a;">ℹ️ Info:</strong> {{ $content['info'] }}
        </div>
    @endif

    <p style="margin-top: 30px; font-size: 12px; color: #878a99;">
        This email was sent to {{ $user->email }}. If you did not expect this email, please contact our support team.
    </p>
@endsection
