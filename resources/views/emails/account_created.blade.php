@extends('emails.layouts.mail')

@section('title', 'Welcome to ' . $business_info['company_name'])

@section('content')
    <div class="appointment-title mt-3">{{ $business_info['company_name'] }}</div>

    <h2>Hello {{ $user->name }}!</h2>
    <p>
        Welcome to <strong>{{ $business_info['company_name'] }}</strong>.
        Your account has been successfully created. Below are your login details:
    </p>

    <div class="info-card-container box-light">
        <div class="info-card-header">
            Account Details
        </div>
        <div class="info-card-content">
            <table class="info-table">
                <tr>
                    <td class="label">Full Name:</td>
                    <td class="value">{{ $user->name }}</td>
                </tr>
                <tr>
                    <td class="label">Email:</td>
                    <td class="value"><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></td>
                </tr>
                @if (!empty($user->phone_number))
                    <tr>
                        <td class="label">Phone:</td>
                        <td class="value">{{ $user->phone_number }}</td>
                    </tr>
                @endif
                @if (!empty($user->employee->designation))
                    <tr>
                        <td class="label">Designation:</td>
                        <td class="value">{{ $user->employee->designation }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Temporary Password:</td>
                    <td class="value"><span class="temp-password">{{ $plainPassword }}</span></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="btn-container">
        <p class="highlight">Please login and change your password for security.</p>
        <a href="{{ $loginUrl }}" class="btn">Login to Your Account</a>
    </div>
@endsection

@section('footer')
    <p>
        {{ $business_info['company_name'] }} | {{ $business_info['phone_number'] }} |
        <a href="mailto:{{ $business_info['email'] }}">{{ $business_info['email'] }}</a><br>
        {{ $business_info['address_line_1'] }}
    </p>
    <p>&copy; {{ date('Y') }} {{ $business_info['company_name'] }}. All rights reserved.</p>
@endsection
