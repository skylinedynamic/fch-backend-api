@extends('emails.layouts.mail')

@section('title', 'Credit profile information updated for iSoftPull data')

@section('content')
    <h4 style="font-size: 18px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Credit Profile Information Updated for iSoftPull data</h4>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        Please upload the credit profile information for the following borrower using iSoftPull data:
    </p>

    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 24px;">
        <p style="margin: 0 0 8px 0;"><strong>Borrower Name:</strong> {{ $request->first_name }} {{ $request->last_name }}</p>
        <p style="margin: 0 0 8px 0;"><strong>Email:</strong> {{ $request->email }}</p>
        <p style="margin: 0;"><strong>Phone:</strong> {{ $request->phone }}</p>
    </div>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        They have already submitted their credit profile authorization. Please upload the iSoftPull data to their profile.
    </p>
@endsection
