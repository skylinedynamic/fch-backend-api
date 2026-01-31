@extends('emails.layouts.mail')

@section('title', 'Appointment Details Updated - ' . $business_info['company_name'])

@php
    $startTime = \Carbon\Carbon::parse($appointment->appointment_date);
    $endTime = (clone $startTime)->addMinutes($appointment->duration_minutes);
@endphp

@section('content')
    <div style="padding-top: 20px;">

        <div class="description-card">
            {!! $appointment->description !!}
        </div>

        <table class="detail-grid" cellspacing="0" cellpadding="0">
            <tr>
                <td class="detail-item">
                    <div class="detail-item-container">
                        <div class="detail-icon">📅</div>
                        <div class="detail-content">
                            <div class="detail-label">New Date</div>
                            <div class="detail-value">{{ $startTime->format('d F, Y') }}</div>
                        </div>
                    </div>
                </td>
                <td class="detail-item">
                    <div class="detail-item-container">
                        <div class="detail-icon">🕒</div>
                        <div class="detail-content">
                            <div class="detail-label">New Time Slot</div>
                            <div class="detail-value">{{ $startTime->format('g:i A') }} - {{ $endTime->format('g:i A') }}
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="detail-item">
                    <div class="detail-item-container">
                        <div class="detail-icon">🌍</div>
                        <div class="detail-content">
                            <div class="detail-label">Time Zone</div>
                            <div class="detail-value">{{ $appointment->timezone ?? 'UTC (GMT+00:00)' }}</div>
                        </div>
                    </div>
                </td>
                <td class="detail-item">
                    <div class="detail-item-container">
                        <div class="detail-icon">👤</div>
                        <div class="detail-content">
                            <div class="detail-label">Scheduled By</div>
                            <div class="detail-value">{{ $business_info['company_name'] }}</div>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>
@endsection
