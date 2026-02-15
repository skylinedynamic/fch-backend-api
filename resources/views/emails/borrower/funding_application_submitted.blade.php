@extends('emails.layouts.mail')

@section('title', 'New Funding Application Submitted')

@section('content')
    <h4>Hello,</h4>

    <p>
        A new funding application has been submitted and requires verification. Please review the details below:
    </p>

    <div class="box-light info-card-container">
        <div class="info-card-content">
            <table class="info-table compact">
                <tr>
                    <td class="label">Client Name:</td>
                    <td class="value">
                        {{ $application->full_name ?? $application->first_name . ' ' . $application->last_name }}</td>
                </tr>
                @if ($application->business_name)
                    <tr>
                        <td class="label">Company:</td>
                        <td class="value">{{ $application->business_name }}</td>
                    </tr>
                @endif
                @if ($application->business_email)
                    <tr>
                        <td class="label">Email:</td>
                        <td class="value">{{ $application->business_email }}</td>
                    </tr>
                @endif
                @if ($application->business_phone_no)
                    <tr>
                        <td class="label">Phone:</td>
                        <td class="value">{{ $application->business_phone_no }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Application ID:</td>
                    <td class="value">{{ $application->application_number }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="btn-container">
        <a href="{{ config('app.frontend_partner_url') }}/intake-review/funding-review/{{ $application->id }}"
            class="btn">Review Application</a>
    </div>
@endsection
