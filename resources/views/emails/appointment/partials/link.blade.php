{{-- Where to see the appointment; the .ics attached adds it to a calendar (R2 BUG-075). --}}
@if (!empty($appointmentUrl))
    <div class="btn-container" style="margin: 10px 0 30px; text-align: center;">
        <a href="{{ $appointmentUrl }}" class="btn"
            style="display: inline-block; background-color: #0ab39c; color: #ffffff !important; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: 500; border-radius: 4px;">View Appointment</a>
    </div>
@endif
<p style="font-size: 12px; color: #878a99; text-align: center; margin: 0 0 20px;">
    {{ ($action ?? null) === 'deleted' ? 'Open the attached calendar file to remove it from your calendar.' : 'Open the attached calendar file to add it to your calendar.' }}
</p>
