@extends('emails.layouts.mail')

@section('title', 'New Profile Change Request Submitted')

@section('content')
    <h4 style="font-size: 15px; font-weight: 600; color: #495057; margin: 0 0 16px 0;">Hello {{ $partnerName }},</h4>

    <p style="line-height: 1.5; margin-bottom: 24px;">
        A new profile change request has been submitted by a borrower and requires your review.
    </p>

    <div class="box-light info-card-container"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 0; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header"
            style="background-color: #f0f7ff; padding: 16px 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 15px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Change Request Summary
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table compact" style="width: 100%; border-collapse: collapse; table-layout: fixed; word-wrap: break-word; margin-bottom: 0;">
                <tr>
                    <td class="label"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Borrower Name:</td>
                    <td class="value"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $borrowerName }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Business Name:</td>
                    <td class="value"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $businessName }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Field Name:</td>
                    <td class="value"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ ucwords(str_replace('_', ' ', $fieldName)) }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Current Value:</td>
                    <td class="value"
                        style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $oldValue }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 8px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Requested Value:</td>
                    <td class="value"
                        style="padding: 8px 0; border-bottom: none; font-size: 13px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        {{ $newValue }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="btn-container" style="margin: 30px 0; text-align: center;">
        <a href="{{ config('app.frontend_partner_url') }}/intake-review/change-requests" class="btn"
            style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">Review
            Change Request</a>
    </div>
@endsection