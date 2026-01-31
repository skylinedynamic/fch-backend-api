@extends('emails.layouts.mail')

@section('title', config('app.name') . ' - Activate Your Four Corner Holdings Account')

@section('content')
    <h4>Hello {{ $user->name }}!</h4>

    <p>
        Your account has been created with <strong>{{ config('app.name') }}</strong>.
        To finalize your setup and activate your account, please complete the payment below.
    </p>

    <!-- Information List -->
    <div class="info-card">
        <div class="info-item">
            <div class="info-label">Full Name</div>
            <div class="info-value">{{ $user->name }}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Email Address</div>
            <div class="info-value">{{ $user->email }}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Payment Amount</div>
            <div class="info-value">{{ number_format($amount, 2) }}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Temporary Password</div>
            <div class="info-value">
                {{ $plainPassword }}</div>
        </div>
    </div>

    <div class="btn-container">
        <p class="highlight">Please complete your payment to finalize your account setup.</p>
        <a href="{{ $paymentLink }}" class="btn">Pay ${{ number_format($amount, 2) }} & Setup Account</a>
    </div>
@endsection
