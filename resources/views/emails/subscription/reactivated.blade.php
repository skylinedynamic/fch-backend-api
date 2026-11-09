@extends('emails.layouts.mail')

@section('title', 'Subscription active again - ' . $siteName)

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello
            <strong>{{ $first_name }}</strong>,
        </h4>

        <p style="line-height: 1.5; margin-bottom: 24px;">
            Your subscription is active again. You have full access to your account, and billing continues on your
            current plan.
        </p>

        @if (!empty($next_billing_date))
            <div class="box-light"
                style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <p style="margin: 0; line-height: 1.5; color: #495057;">
                    Your next payment is due on <strong>{{ $next_billing_date }}</strong>. It is charged automatically to
                    your saved payment method.
                </p>
            </div>
        @endif

        @if (!empty($portal_url))
            <div class="btn-container" style="margin: 30px 0; text-align: center;">
                <a href="{{ $portal_url }}" class="btn"
                    style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">
                    View Subscription
                </a>
            </div>
        @endif

        <p style="color: #64748b; font-size: 12px; line-height: 1.6; margin-top: 24px;">
            If you did not expect this, please contact our support team through your portal.
        </p>

        <p style="color: #64748b; line-height: 1.5; margin-top: 24px; margin-bottom: 0;">
            <strong style="color: #495057;">{{ $company_name }}</strong><br>
            Business Credit & Funding Platform
        </p>
    </div>
@endsection
