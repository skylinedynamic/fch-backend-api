{{-- What the meeting is and with whom (R2 BUG-063): the title was only in the subject line. --}}
<h2 style="font-size: 18px; font-weight: 700; color: #1a2e44; margin: 0 0 6px 0; text-align: left; word-break: break-word;">
    {{ $appointment->subject }}
</h2>
<table cellspacing="0" cellpadding="0" style="width: 100%; margin-bottom: 20px; border-collapse: collapse; font-size: 14px; color: #1a2e44;">
    @if (!empty($appointment->meeting_type))
        <tr>
            <td style="padding: 4px 0; width: 120px; color: #878a99; font-size: 11px; font-weight: 700; text-transform: uppercase; vertical-align: top;">Meeting Type</td>
            <td style="padding: 4px 0; font-weight: 600;">{{ $appointment->meeting_type }}</td>
        </tr>
    @endif
    @if (!empty($attendeeNames))
        <tr>
            <td style="padding: 4px 0; width: 120px; color: #878a99; font-size: 11px; font-weight: 700; text-transform: uppercase; vertical-align: top;">With</td>
            <td style="padding: 4px 0; font-weight: 600;">{{ $attendeeNames }}</td>
        </tr>
    @endif
</table>
