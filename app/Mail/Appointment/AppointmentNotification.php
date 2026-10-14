<?php

namespace App\Mail\Appointment;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\Appointment\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $appointment;
    public $action; // 'created', 'updated', 'deleted'
    public $businessInfo;
    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment, string $action, array $businessInfo = [], ?int $partnerId = null)
    {
        $this->appointment = $appointment;
        $this->action = $action;
        $this->businessInfo = $businessInfo;
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

        return new Content(
            view: $viewName,
            with: [
                'appointment' => $this->appointment,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'action' => $this->action,
                'assigneeName' => $this->appointment->assignedFor->name ?? 'User',
                'business_info' => [
                    'company_name' => $this->businessInfo['company_name'] ?? '',
                    'email' => $this->businessInfo['email'] ?? '',
                    'phone_number' => $this->businessInfo['phone_number'] ?? '',
                    'address_line_1' => $this->businessInfo['address_line_1'] ?? '',
                ],
            ],
        );
    }
}
