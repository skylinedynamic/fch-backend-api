@extends('emails.layouts.mail')

@section('title', 'New Pre-Qualification Application Submitted')

@section('content')
    <div class="appointment-title mt-3"
        style="font-size: 20px; font-weight: 700; color: #1a2e44; margin-bottom: 24px; margin-top: 16px;">New
        Pre-Qualification Application</div>

    <p style="line-height: 1.5; margin-bottom: 16px;">A new pre-qualification application has been submitted by
        <strong>{{ $preQualification->first_name }} {{ $preQualification->last_name }}</strong>.</p>

    <div class="info-card-container box-light"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header"
            style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Applicant Details
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Name:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->first_name }} {{ $preQualification->last_name }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Email:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->email }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Phone:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->phone_number }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="info-card-container box-light"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header"
            style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Business Information
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Business Name:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->business_name }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Industry:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->industry_type }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        State of Inc.:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->state_of_incorporation }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Time in Business:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->years_in_business }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Monthly Revenue:</td>
                    <td class="value amount-value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 600; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->monthly_revenue }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Annual Revenue:</td>
                    <td class="value amount-value"
                        style="padding: 10px 0; border-bottom: none; font-size: 16px; font-weight: 600; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->annual_revenue }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="info-card-container box-light"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
        <div class="info-card-header"
            style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
            Funding Request
        </div>
        <div class="info-card-content" style="padding: 20px;">
            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Requested Amount:</td>
                    <td class="value amount-value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 16px; font-weight: 600; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->funding_requested_amount }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Use of Funds:</td>
                    <td class="value"
                        style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">
                        {{ $preQualification->purpose_of_funding }}</td>
                </tr>
                <tr>
                    <td class="label"
                        style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">
                        Credit Score (Est):</td>
                    <td class="value highlight-value"
                        style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 600; color: #1e3a8a; background-color: #eff6ff; padding: 4px 8px; border-radius: 4px; font-family: monospace; text-align: right; padding-right: 10px;">
                        {{ $preQualification->personal_credit_score }}</td>
                </tr>
            </table>
        </div>
    </div>

    @if ($preQualification->notes)
        <div class="info-card-container box-light"
            style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 0; border-radius: 8px; margin-bottom: 24px; text-align: left; overflow: hidden;">
            <div class="info-card-header"
                style="background-color: #f0f7ff; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">
                Notes
            </div>
            <div class="info-card-content" style="padding: 20px; font-size: 14px; line-height: 1.6; color: #495057;">
                <p style="margin: 0;">{{ $preQualification->notes }}</p>
            </div>
        </div>
    @endif
@endsection
