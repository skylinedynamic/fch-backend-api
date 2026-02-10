@extends('emails.layouts.mail')

@section('title', 'New Demo Request from ' . $demoRequest->customer_name)

@section('content')
    <div class="appointment-title mt-3">New Demo Request Received</div>

    <h4>Hello {{ $assignedUser->name ?? 'Team' }},</h4>
    <p>
        A new demo request has been submitted by <strong>{{ $demoRequest->customer_name }}</strong>.
        Below are the request details:
    </p>

    <div class="info-card-container box-light">
        <div class="info-card-header">
            Request Details
        </div>
        <div class="info-card-content">
            <table class="info-table">
                <tr>
                    <td class="label">Customer Name:</td>
                    <td class="value">{{ $demoRequest->customer_name }}</td>
                </tr>
                <tr>
                    <td class="label">Full Name:</td>
                    <td class="value">{{ $demoRequest->full_name }}</td>
                </tr>
                <tr>
                    <td class="label">Phone:</td>
                    <td class="value">{{ $demoRequest->phone }}</td>
                </tr>
                <tr>
                    <td class="label">Email:</td>
                    <td class="value">{{ $demoRequest->email }}</td>
                </tr>
                @if (!empty($demoRequest->country))
                    <tr>
                        <td class="label">Country:</td>
                        <td class="value">{{ $demoRequest->country }}</td>
                    </tr>
                @endif
                @if (!empty($demoRequest->website))
                    <tr>
                        <td class="label">Website:</td>
                        <td class="value"><a href="{{ $demoRequest->website }}"
                                target="_blank">{{ $demoRequest->website }}</a></td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Demo Start Date:</td>
                    <td class="value">{{ $demoRequest->demo_start ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="label">Demo End Date:</td>
                    <td class="value">{{ $demoRequest->demo_end ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="label">Status:</td>
                    <td class="value"><strong style="text-transform:capitalize;">{{ $demoRequest->status }}</strong></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="btn-container">
        <a href="{{ url('/') }}" class="btn">View in Dashboard</a>
    </div>
@endsection
