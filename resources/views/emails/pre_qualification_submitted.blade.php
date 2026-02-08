@extends('emails.layouts.mail')

@section('title', 'New Pre-Qualification Application Submitted')

@section('content')
    <div class="appointment-title mt-3">New Pre-Qualification Application</div>

    <p>A new pre-qualification application has been submitted by {{ $preQualification->first_name }}
        {{ $preQualification->last_name }}.</p>

    <div class="info-card-container box-light">
        <div class="info-card-header">
            Applicant Details
        </div>
        <div class="info-card-content">
            <table class="info-table">
                <tr>
                    <td class="label">Name:</td>
                    <td class="value">{{ $preQualification->first_name }} {{ $preQualification->last_name }}</td>
                </tr>
                <tr>
                    <td class="label">Email:</td>
                    <td class="value">{{ $preQualification->email }}</td>
                </tr>
                <tr>
                    <td class="label">Phone:</td>
                    <td class="value">{{ $preQualification->phone_number }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="info-card-container box-light">
        <div class="info-card-header">
            Business Information
        </div>
        <div class="info-card-content">
            <table class="info-table">
                <tr>
                    <td class="label">Business Name:</td>
                    <td class="value">{{ $preQualification->business_name }}</td>
                </tr>
                <tr>
                    <td class="label">Industry:</td>
                    <td class="value">{{ $preQualification->industry_type }}</td>
                </tr>
                <tr>
                    <td class="label">State of Inc.:</td>
                    <td class="value">{{ $preQualification->state_of_incorporation }}</td>
                </tr>
                <tr>
                    <td class="label">Time in Business:</td>
                    <td class="value">{{ $preQualification->years_in_business }}</td>
                </tr>
                <tr>
                    <td class="label">Monthly Revenue:</td>
                    <td class="value amount-value">{{ $preQualification->monthly_revenue }}</td>
                </tr>
                <tr>
                    <td class="label">Annual Revenue:</td>
                    <td class="value amount-value">{{ $preQualification->annual_revenue }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="info-card-container box-light">
        <div class="info-card-header">
            Funding Request
        </div>
        <div class="info-card-content">
            <table class="info-table">
                <tr>
                    <td class="label">Requested Amount:</td>
                    <td class="value amount-value">{{ $preQualification->funding_requested_amount }}</td>
                </tr>
                <tr>
                    <td class="label">Use of Funds:</td>
                    <td class="value">{{ $preQualification->purpose_of_funding }}</td>
                </tr>
                <tr>
                    <td class="label">Credit Score (Est):</td>
                    <td class="value highlight-value">{{ $preQualification->personal_credit_score }}</td>
                </tr>
            </table>
        </div>
    </div>

    @if ($preQualification->notes)
        <div class="info-card-container box-light">
            <div class="info-card-header">
                Notes
            </div>
            <div class="info-card-content">
                <p>{{ $preQualification->notes }}</p>
            </div>
        </div>
    @endif
@endsection
