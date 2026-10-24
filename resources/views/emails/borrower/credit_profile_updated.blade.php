@extends('emails.layouts.mail')

@section('title', __('credit_profile_request.updated_mail_subject'))

@section('content')
    <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">{{ __('credit_profile_request.updated_mail_heading') }}</h4>

    <p style="line-height: 1.5; margin-bottom: 16px;">
        {{ __('credit_profile_request.updated_mail_intro', ['name' => $borrowerName]) }}
    </p>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        {{ __('credit_profile_request.updated_mail_body') }}
    </p>

    @if ($profileUrl)
        <div class="btn-container" style="margin: 30px 0; text-align: center;">
            <a href="{{ $profileUrl }}" class="btn"
                style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">{{ __('credit_profile_request.updated_mail_action') }}</a>
        </div>
    @endif
@endsection
