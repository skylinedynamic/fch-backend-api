@extends('emails.layouts.mail')

@section('title', 'Welcome to ' . $siteName)

@section('content')

    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $user->name }}!</h4>
    <p style="line-height: 1.5; margin-bottom: 16px;">
        Welcome to <strong>{{ $siteName }}</strong>.
        Your account has been successfully created. Below are your login details:
    </p>

    <div class="info-card-container box-light"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header"
            style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Account Details
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Full Name:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $user->name }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Email:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $user->email }}</td>
                </tr>
                @if (!empty($user->phone_number))
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Phone:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $user->phone_number }}</td>
                    </tr>
                @endif
                @if (!empty($user->employee->designation))
                    <tr>
                        <td class="label"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                            Designation:</td>
                        <td class="value"
                            style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                            {{ $user->employee->designation }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: none; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Temporary Password:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        <span class="temp-password"
                            style="background: #ffffff; padding: 6px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-family: 'Courier New', Courier, monospace; font-weight: 600; color: #1e3a8a; display: inline-block; letter-spacing: 1px;">{{ $plainPassword }}</span>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    @if (!empty($loginUrl))
    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <p class="highlight" style="color: #1e3a8a; font-weight: 700; margin-bottom: 20px; font-size: 14px;">Please login
            and change your password for security.</p>
        <a href="{{ $loginUrl }}" class="btn"
            style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Login
            to Your Account</a>
    </div>
    @endif
@endsection
