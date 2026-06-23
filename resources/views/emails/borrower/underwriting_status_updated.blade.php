@extends('emails.layouts.mail')

@section('title', 'Underwriting Review ' . $fundingReview->status)

@section('content')
    <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $fundingReview->borrower->first_name }},</h4>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        Your underwriting review for <strong>{{ $fundingReview->funding_type }}</strong> has been <strong>{{ strtolower($fundingReview->status) }}</strong>.
    </p>

    @if($fundingReview->status === 'Rejected' && $fundingReview->reject_reason)
    <div class="box-light info-card-container" style="background-color: #fef2f2; border: 1px solid #fca5a5; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-content" style="padding: 20px;">
            <p style="margin: 0; font-size: 13px; color: #991b1b;">
                <strong>Reason:</strong> {{ $fundingReview->reject_reason }}
            </p>
        </div>
    </div>
    @endif

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ config('app.frontend_url') }}/funding/{{ $fundingReview->id }}/review"
            class="btn" style="display: inline-block; background-color: #1e3a8a; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View Review Details</a>
    </div>
@endsection
