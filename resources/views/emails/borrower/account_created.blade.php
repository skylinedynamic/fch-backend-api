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
    <div class="box-light info-card-container">
        <div class="info-card-header">Account Details</div>
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
                @if (!empty($user->phone_number))
                    <tr>
                        <td class="label">Phone</td>
                        <td class="value">{{ $user->phone_number }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Temporary Password</td>
                    <td class="value"><span class="highlight-value">{{ $plainPassword }}</span></td>
                </tr>
            </table>
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
