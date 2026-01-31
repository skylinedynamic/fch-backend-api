@extends('emails.layouts.mail')

@section('title', config('app.name') . ' - Welcome')


@section('content')
    <h4>Hello {{ $user->name }}!</h4>

    <p>
        @if (!empty($is_update))
            Your borrower account has been successfully updated. We're excited to have you continue your
            journey with us.
        @else
            Your borrower account has been successfully created. We're excited to have you on board!
        @endif
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
        @if (!empty($user->phone_number))
            <div class="info-item">
                <div class="info-label">Phone</div>
                <div class="info-value">{{ $user->phone_number }}</div>
            </div>
        @endif
        <div class="info-item">
            <div class="info-label">Temporary Password</div>
            <div class="info-value">{{ $plainPassword }}</div>
        </div>
    </div>

    <div class="btn-container">
        @if (!empty($paymentLink))
            <p class="highlight">Please complete your payment of <span
                    class="highlight">${{ number_format($amount, 2) }}</span> to finalize setup.</p>
            <a href="{{ $paymentLink }}" class="btn">Pay ${{ number_format($amount, 2) }} Now</a>
        @else
            <a href="{{ $loginUrl }}" class="btn">Login to Dashboard</a>
        @endif
    </div>
@endsection
