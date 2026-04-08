@extends('emails.layouts.mail')

@if($is_pre_qualification)
@section('title', 'New Pre-Qualification Submitted - Website')
@else
@section('title', $type === 'website' ? 'New Lead Submitted - Website' : 'New Manual Lead Created')
@endif

@section('content')
    <div class="description-card">
        <p class="mb-3">Hello,</p>

        <p class="mb-5">
            @if($is_pre_qualification)
                A new <strong>Pre-Qualification Request</strong> has been submitted through the website.
            @else
                A new <strong>Lead</strong> has been successfully created.
            @endif
            Please review the details below.
        </p>

        {{-- Contact Information --}}
        <div class="section-label mb-2">Contact Information</div>
        <table class="detail-table">
            @if($lead->contact_person)
                <tr>
                    <td class="label">Name</td>
                    <td class="value">{{ $lead->contact_person }}</td>
                </tr>
            @endif
            @if($lead->email)
                <tr>
                    <td class="label">Email</td>
                    <td class="value">{{ $lead->email }}</td>
                </tr>
            @endif
            @if ($lead->phone_number)
                <tr>
                    <td class="label">Phone</td>
                    <td class="value">{{ $lead->phone_number }}</td>
                </tr>
            @endif
            @if ($lead->company_name)
                <tr>
                    <td class="label">Company</td>
                    <td class="value">{{ $lead->company_name }}</td>
                </tr>
            @endif
            @if ($lead->domain)
                <tr>
                    <td class="label">Website</td>
                    <td class="value">{{ $lead->domain }}</td>
                </tr>
            @endif
        </table>

        @if($is_pre_qualification && isset($prequalification))
            {{-- Pre-Qualification Details --}}
            <div class="section-label mb-2 mt-4">Pre-Qualification Details</div>
            <table class="detail-table">
                @if(isset($prequalification->business_name))
                    <tr>
                        <td class="label">Business Name</td>
                        <td class="value">{{ $prequalification->business_name }}</td>
                    </tr>
                @endif
                @if(isset($prequalification->industry_type))
                    <tr>
                        <td class="label">Industry</td>
                        <td class="value">{{ $prequalification->industry_type }}</td>
                    </tr>
                @endif
                @if(isset($prequalification->business_structure))
                    <tr>
                        <td class="label">Structure</td>
                        <td class="value">{{ $prequalification->business_structure }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Monthly Revenue</td>
                    <td class="value highlight">
                        @if (is_numeric($prequalification->monthly_revenue))
                            ${{ number_format($prequalification->monthly_revenue, 2) }}
                        @else
                            {{ $prequalification->monthly_revenue }}
                        @endif
                    </td>
                </tr>
                @if(isset($prequalification->annual_revenue))
                    <tr>
                        <td class="label">Annual Revenue</td>
                        <td class="value">
                            @if (is_numeric($prequalification->annual_revenue))
                                ${{ number_format($prequalification->annual_revenue, 2) }}
                            @else
                                {{ $prequalification->annual_revenue }}
                            @endif
                        </td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Requested Amount</td>
                    <td class="value highlight">
                        @if (is_numeric($prequalification->funding_requested_amount))
                            ${{ number_format((float) $prequalification->funding_requested_amount, 2) }}
                        @else
                            {{ $prequalification->funding_requested_amount }}
                        @endif
                    </td>
                </tr>
                @if(isset($prequalification->funding_urgency))
                    <tr>
                        <td class="label">Urgency</td>
                        <td class="value">{{ $prequalification->funding_urgency }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Credit Score</td>
                    <td class="value">{{ $prequalification->personal_credit_score }}</td>
                </tr>
                @if(isset($prequalification->years_in_business))
                    <tr>
                        <td class="label">Years in Business</td>
                        <td class="value">{{ $prequalification->years_in_business }}</td>
                    </tr>
                @endif
            </table>
        @endif

        @if ($lead->requirement_details)
            {{-- Message / Requirements --}}
            <div class="section-label mb-2 mt-4">Message / Requirements</div>
            <div class="box-light">
                <p class="mb-0">{{ $lead->requirement_details }}</p>
            </div>
        @endif

        {{-- Lead Metadata --}}
        <div class="section-label mb-2 mt-4">Internal Details</div>
        <table class="detail-table">
            <tr>
                <td class="label">Lead ID</td>
                <td class="value"><strong>{{ $lead->lead_id }}</strong></td>
            </tr>
            <tr>
                <td class="label">Submission Date</td>
                <td class="value">{{ $lead->created_at?->format('M d, Y h:i A') }}</td>
            </tr>
            <tr>
                <td class="label">Source</td>
                <td class="value">{{ $lead->lead_source }}</td>
            </tr>
        </table>

        <div class="btn-container">
            @php
                $adminUrl = config('app.frontend_partner_url') ?: config('app.frontend_partner_url');
                $leadUrl = rtrim($adminUrl, '/') . '/leads';
            @endphp
            <a href="{{ $leadUrl }}" class="btn">View Lead in Portal</a>
        </div>
    </div>
@endsection