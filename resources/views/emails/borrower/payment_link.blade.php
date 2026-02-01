@extends('emails.layouts.mail')

@section('title', config('app.name') . ' - Activate Your Four Corner Holdings Account')

@section('content')
    <h4>Hello {{ $user->name }}!</h4>

    <p>
        Your account has been created with <strong>{{ config('app.name') }}</strong>.
        To finalize your setup and activate your account, please complete the payment below.
    </p>

    <!-- Information List -->
    <div class="box-light info-card-container">
        <div class="info-card-header">Payment Required</div>
        <div class="info-card-content">
            <table class="info-table">
                <tr>
                    <td class="label">Full Name</td>
                    <td class="value">{{ $user->name }}</td>
                </tr>
                <tr>
                    <td class="label">Email Address</td>
                    <td class="value">{{ $user->email }}</td>
                </tr>
                <tr>
                    <td class="label">Payment Amount</td>
                    <td class="value amount-value">${{ number_format($amount, 2) }}</td>
                </tr>
                <tr>
                    <td class="label">Temporary Password</td>
                    <td class="value"><span class="highlight-value">{{ $plainPassword }}</span></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="btn-container">
        <p class="highlight">Please complete your payment to finalize your account setup.</p>
        <a href="{{ $paymentLink }}" class="btn">Pay ${{ number_format($amount, 2) }} & Setup Account</a>
    </div>
@endsection
