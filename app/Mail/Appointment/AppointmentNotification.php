<?php

namespace App\Mail\Appointment;

use App\Models\Appointment\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentNotification extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    public $appointment;
    public $action; // 'created', 'updated', 'deleted'
    public $businessInfo;

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment, string $action, array $businessInfo = [], ?int $partnerId = null)
    {
        $this->appointment = $appointment;
        $this->action = $action;
        $this->businessInfo = $businessInfo;
        $this->resolveMailConfig($partnerId);
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
        $siteName = $branding['siteName'];
        $subject = "{$subjectText} - {$siteName}: {$this->appointment->subject}";

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->getFromAddress(),
                $this->getFromName()
            ),
            subject: $this->getDynamicSubject($subject),
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

        return new Content(
            view: $viewName,
            with: array_merge([
                'appointment' => $this->appointment,
                'action' => $this->action,
                'assigneeName' => $this->appointment->assignedFor->name ?? 'User',
            ], $this->getBrandingData()),
        );
    }
}
