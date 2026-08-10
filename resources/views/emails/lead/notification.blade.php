@extends('emails.layouts.mail')

@if($is_pre_qualification)
@section('title', 'New Pre-Qualification Submitted - Website')
@else
@section('title', $type === 'website' ? 'New Lead Submitted - Website' : 'New Manual Lead Created')
@endif

@section('content')
    <div class="description-card" style="margin-bottom: 30px; text-align: left;">
        <p style="line-height: 1.5; margin-bottom: 16px;">Hello,</p>

        <p style="line-height: 1.5; margin-bottom: 32px;">
            @if($is_pre_qualification)
                A new <strong>Pre-Qualification Request</strong> has been submitted through the website.
            @else
                A new <strong>Lead</strong> has been successfully created.
            @endif
            Please review the details below.
        </p>

        {{-- Contact Information --}}
        <div class="section-label mb-2" style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">Contact Information</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            @if($lead->contact_person)
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Name</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $lead->contact_person }}</td>
                </tr>
            @endif
            @if($lead->email)
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Email</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #1e3a8a; text-align: right; padding-right: 10px;">{{ $lead->email }}</td>
                </tr>
            @endif
            @if ($lead->phone_number)
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Phone</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $lead->phone_number }}</td>
                </tr>
            @endif
            @if ($lead->company_name)
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Company</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $lead->company_name }}</td>
                </tr>
            @endif
            @if ($lead->domain)
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Website</td>
                    <td class="value" style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #1e3a8a; text-align: right; padding-right: 10px;">{{ $lead->domain }}</td>
                </tr>
            @endif
        </table>

        @if($is_pre_qualification && isset($prequalification))
            {{-- Pre-Qualification Details --}}
            <div class="section-label mb-2 mt-4" style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-top: 32px;">Pre-Qualification Details</div>
            <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
                @if(isset($prequalification->business_name))
                    <tr>
                        <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Business Name</td>
                        <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">{{ $prequalification->business_name }}</td>
                    </tr>
                @endif
                @if(isset($prequalification->industry_type))
                    <tr>
                        <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Industry</td>
                        <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $prequalification->industry_type }}</td>
                    </tr>
                @endif
                @if(isset($prequalification->business_structure))
                    <tr>
                        <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Structure</td>
                        <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $prequalification->business_structure }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Monthly Revenue</td>
                    <td class="value highlight" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 15px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        @if (is_numeric($prequalification->monthly_revenue))
                            ${{ number_format($prequalification->monthly_revenue, 2) }}
                        @else
                            {{ $prequalification->monthly_revenue }}
                        @endif
                    </td>
                </tr>
                @if(isset($prequalification->annual_revenue))
                    <tr>
                        <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Annual Revenue</td>
                        <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #0f172a; text-align: right; padding-right: 10px;">
                            @if (is_numeric($prequalification->annual_revenue))
                                ${{ number_format($prequalification->annual_revenue, 2) }}
                            @else
                                {{ $prequalification->annual_revenue }}
                            @endif
                        </td>
                    </tr>
                @endif
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Requested Amount</td>
                    <td class="value highlight" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 15px; font-weight: 700; color: #1e3a8a; text-align: right; padding-right: 10px;">
                        @if (is_numeric($prequalification->funding_requested_amount))
                            ${{ number_format((float) $prequalification->funding_requested_amount, 2) }}
                        @else
                            {{ $prequalification->funding_requested_amount }}
                        @endif
                    </td>
                </tr>
                @if(isset($prequalification->funding_urgency))
                    <tr>
                        <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Urgency</td>
                        <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $prequalification->funding_urgency }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Credit Score</td>
                    <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 600; color: #1e3a8a; background-color: #eff6ff; padding: 4px 8px; border-radius: 4px; font-family: monospace; text-align: right; padding-right: 10px;">{{ $prequalification->personal_credit_score }}</td>
                </tr>
                @if(isset($prequalification->years_in_business))
                    <tr>
                        <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Years in Business</td>
                        <td class="value" style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #0f172a; text-align: right; padding-right: 10px;">{{ $prequalification->years_in_business }}</td>
                    </tr>
                @endif
            </table>
        @endif

        @if ($lead->requirement_details)
            {{-- Message / Requirements --}}
            <div class="section-label mb-2 mt-4" style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-top: 32px;">Message / Requirements</div>
            <div class="box-light" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <div class="mb-0" style="margin: 0; line-height: 1.6; color: #495057;">{!! $lead->requirement_details !!}</div>
            </div>
        @endif

        {{-- Lead Metadata --}}
        <div class="section-label mb-2 mt-4" style="font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-top: 32px;">Internal Details</div>
        <table class="detail-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Lead ID</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700; color: #0f172a; text-align: right; padding-right: 10px;"><strong>{{ $lead->lead_id }}</strong></td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Submission Date</td>
                <td class="value" style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">{{ $lead->created_at?->format('M d, Y h:i A') }}</td>
            </tr>
            <tr>
                <td class="label" style="padding: 10px 0; border-bottom: none; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 40%; padding-left: 10px;">Source</td>
                <td class="value" style="padding: 10px 0; border-bottom: none; font-size: 13px; font-weight: 500; color: #495057; text-align: right; padding-right: 10px;">{{ $lead->lead_source }}</td>
            </tr>
        </table>

        <div class="btn-container" style="margin: 30px 0; text-align: center;">
            <a href="{{ $leadUrl }}" class="btn" style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View Lead in Portal</a>
        </div>
    </div>
@endsection