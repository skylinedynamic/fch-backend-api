@extends('emails.layouts.mail')

@section('title', 'Appointment Cancelled')

@php
    // Stored in UTC; shown in the zone it was booked in, which the email names.
    $startTime = \App\Support\Appointment\AppointmentTime::inBookedZone($appointment->appointment_date, $appointment->timezone);
    $endTime = (clone $startTime)->addMinutes($appointment->duration_minutes);
@endphp

@section('content')
    <div style="padding-top: 20px;">
        <div class="alert alert-danger" role="alert"
            style="background-color: #fef2f2; border: 1px solid #fee2e2; color: #ef4444; padding: 16px 20px; border-radius: 6px; margin-bottom: 24px; font-size: 14px; line-height: 1.5; text-align: center;">
            The following appointment has been <strong>cancelled</strong>.
        </div>

        <div class="mt-2" style="margin-bottom: 30px; text-align: left;">
            {!! $appointment->description !!}
        </div>

        <table class="detail-grid" cellspacing="0" cellpadding="0"
            style="width: 100%; margin-bottom: 30px; border-collapse: collapse;">
            <tr>
                <td class="detail-item" style="padding: 15px 10px; width: 50%; border-bottom: 1px solid #f1f5f9;">
                    <div class="detail-item-container" style="display: table; width: 100%;">
                        <div class="detail-icon"
                            style="display: table-cell; width: 32px; vertical-align: top; padding-top: 4px; font-size: 18px;">
                            📅</div>
                        <div class="detail-content" style="display: table-cell; vertical-align: top; padding-left: 8px;">
                            <div class="detail-label"
                                style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; margin-bottom: 2px;">
                                Original Date</div>
                            <div class="detail-value" style="font-size: 14px; font-weight: 700; color: #1a2e44;">
                                {{ $startTime->format('d F, Y') }}</div>
                        </div>
                    </div>
                </td>
                <td class="detail-item" style="padding: 15px 10px; width: 50%; border-bottom: 1px solid #f1f5f9;">
                    <div class="detail-item-container" style="display: table; width: 100%;">
                        <div class="detail-icon"
                            style="display: table-cell; width: 32px; vertical-align: top; padding-top: 4px; font-size: 18px;">
                            🕒</div>
                        <div class="detail-content" style="display: table-cell; vertical-align: top; padding-left: 8px;">
                            <div class="detail-label"
                                style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; margin-bottom: 2px;">
                                Time Slot</div>
                            <div class="detail-value" style="font-size: 14px; font-weight: 700; color: #1a2e44;">
                                {{ $startTime->format('g:i A') }} - {{ $endTime->format('g:i A') }}
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="detail-item" style="padding: 15px 10px; width: 50%;">
                    <div class="detail-item-container" style="display: table; width: 100%;">
                        <div class="detail-icon"
                            style="display: table-cell; width: 32px; vertical-align: top; padding-top: 4px; font-size: 18px;">
                            🌍</div>
                        <div class="detail-content" style="display: table-cell; vertical-align: top; padding-left: 8px;">
                            <div class="detail-label"
                                style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; margin-bottom: 2px;">
                                Time Zone</div>
                            <div class="detail-value" style="font-size: 14px; font-weight: 700; color: #1a2e44;">
                                {{ \App\Support\Appointment\AppointmentTime::zone($appointment->timezone) ?? 'UTC' }} (GMT{{ $startTime->format('P') }})</div>
                        </div>
                    </div>
                </td>
                <td class="detail-item" style="padding: 15px 10px; width: 50%;">
                    <div class="detail-item-container" style="display: table; width: 100%;">
                        <div class="detail-icon"
                            style="display: table-cell; width: 32px; vertical-align: top; padding-top: 4px; font-size: 18px;">
                            👤</div>
                        <div class="detail-content" style="display: table-cell; vertical-align: top; padding-left: 8px;">
                            <div class="detail-label"
                                style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #878a99; margin-bottom: 2px;">
                                Scheduled By</div>
                            <div class="detail-value" style="font-size: 14px; font-weight: 700; color: #1a2e44;">
                                {{ $business_info['company_name'] }}</div>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>
@endsection
