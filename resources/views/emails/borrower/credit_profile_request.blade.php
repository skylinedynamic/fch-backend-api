@extends('emails.layouts.mail')

@section('title', 'Credit profile information updated for iSoftPull data')

@section('content')
    <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Credit Profile Information Updated for iSoftPull data</h4>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        Please upload the credit profile information for the following borrower using iSoftPull data:
    </p>

    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 24px;">
        <p style="margin: 0 0 8px 0;"><strong>Credit Profile Name:</strong> {{ trim(preg_replace('/\s+/', ' ', $creditRequest->first_name . ' ' . $creditRequest->middle_name . ' ' . $creditRequest->last_name)) }}</p>
        <p style="margin: 0 0 8px 0;"><strong>Credit Profile Email:</strong> {{ $creditRequest->email }}</p>
        <p style="margin: 0 0 8px 0;"><strong>Credit Profile Phone:</strong> {{ $creditRequest->phone }}</p>
    </div>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        They have already submitted their credit profile authorization. Please upload the iSoftPull data to their profile.
    </p>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ $requestUrl }}" class="btn"
            style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View Credit Profile Request</a>
    </div>
@endsection
