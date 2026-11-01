<?php

namespace App\Mail\Appointment;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\Appointment\Appointment;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $appointment;
    public $action; // 'created', 'updated', 'deleted'
    public $businessInfo;
    /** Who the email is for - picks the portal the "View appointment" link opens. */
    public ?User $recipient;
    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment, string $action, array $businessInfo = [], ?int $partnerId = null, ?User $recipient = null)
    {
        $this->appointment = $appointment;
        $this->action = $action;
        $this->businessInfo = $businessInfo;
        $this->recipient = $recipient ?? $appointment->assignedFor;
        // The partner the appointment belongs to, when the caller names it;
        // otherwise whoever is signed in.
        $this->setMailPartnerId($partnerId);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subjectText = match ($this->action) {
            'created' => 'New Appointment Scheduled',
            'updated' => 'Appointment Updated',
            'deleted' => 'Appointment Cancelled',
            default => 'Appointment Notification',
        };

        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: $subjectText . ': ' . $this->appointment->subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Determine view based on action
        $viewName = match ($this->action) {
            'updated' => 'emails.appointment.update',
            'deleted' => 'emails.appointment.delete',
            default => 'emails.appointment.create',
        };

        if (!isset($this->dynamicData['siteName'])) {
            $branding = $this->getBrandingData();
            $this->dynamicData['siteName'] = $branding['siteName'];
            $this->dynamicData['logoUrl'] = $branding['logoUrl'];
        }

        return new Content(
            view: $viewName,
            with: [
                'appointment' => $this->appointment,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'action' => $this->action,
                'assigneeName' => $this->appointment->assignedFor->name ?? 'User',
                // What the meeting is and with whom, and where to see it (R2 BUG-063).
                'attendeeNames' => $this->attendeeNames(),
                'appointmentUrl' => $this->appointmentUrl(),
                'business_info' => [
                    'company_name' => $this->businessInfo['company_name'] ?? '',
                    'email' => $this->businessInfo['email'] ?? '',
                    'phone_number' => $this->businessInfo['phone_number'] ?? '',
                    'address_line_1' => $this->businessInfo['address_line_1'] ?? '',
                ],
            ],
        );
    }

    /**
     * An .ics file so the meeting can be added to (or, on a cancellation,
     * removed from) the recipient's calendar (R2 BUG-063).
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $ics = $this->calendarFile();

        return [
            Attachment::fromData(fn () => $ics, 'appointment.ics')
                ->withMime('text/calendar; charset=utf-8; method=' . ($this->action === 'deleted' ? 'CANCEL' : 'REQUEST')),
        ];
    }

    /** The organiser and the attendee, by name. */
    private function attendeeNames(): string
    {
        return collect([$this->appointment->scheduledBy?->name, $this->appointment->assignedFor?->name])
            ->filter()
            ->unique()
            ->implode(', ');
    }

    /**
     * The recipient's own portal's appointments page: the borrower portal's
     * /appointment for a client, the staff portal's /appointments otherwise.
     * Empty when the recipient's partner has no portal a link could open.
     */
    private function appointmentUrl(): string
    {
        $partnerId = $this->recipient?->partner_id ?? $this->mailPartnerId ?? null;

        if ($this->recipient?->user_type === User::BORROWER) {
            return concatUrl($partnerId ? getBorrowerDomain($partnerId) : null, 'appointment');
        }

        return concatUrl($partnerId ? getPartnerDomain($partnerId) : null, 'appointments', config('app.frontend_partner_url'));
    }

    /** An iCalendar (RFC 5545) event for the appointment, times in UTC as stored. */
    private function calendarFile(): string
    {
        $appointment = $this->appointment;
        $start = Carbon::parse($appointment->appointment_date, 'UTC');
        $end = (clone $start)->addMinutes((int) ($appointment->duration_minutes ?: 30));
        $cancelled = $this->action === 'deleted';
        $escape = fn (?string $value) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string) $value);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//FCH//Appointments//EN',
            'METHOD:' . ($cancelled ? 'CANCEL' : 'REQUEST'),
            'BEGIN:VEVENT',
            'UID:appointment-' . $appointment->id . '@' . (parse_url(config('app.url'), PHP_URL_HOST) ?: 'fch'),
            'SEQUENCE:' . ($appointment->updated_at ? $appointment->updated_at->getTimestamp() : 0),
            'DTSTAMP:' . now('UTC')->format('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . $escape($appointment->subject),
        ];

        if ($appointment->meeting_type) {
            $lines[] = 'CATEGORIES:' . $escape($appointment->meeting_type);
        }
        if ($appointment->location) {
            $lines[] = 'LOCATION:' . $escape($appointment->location);
        }
        if ($url = $this->appointmentUrl()) {
            $lines[] = 'URL:' . $url;
        }

        $lines[] = 'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }
}
