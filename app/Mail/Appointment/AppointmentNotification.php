<?php

namespace App\Mail\Appointment;

use App\Models\Appointment\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $appointment;
    public $action; // 'created', 'updated', 'deleted'
    public $businessInfo;

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment, string $action, array $businessInfo = [])
    {
        $this->appointment = $appointment;
        $this->action = $action;
        $this->businessInfo = $businessInfo;
    }
 
    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = match ($this->action) {
            'created' => 'New Appointment Scheduled',
            'updated' => 'Appointment Updated',
            'deleted' => 'Appointment Cancelled',
            default => 'Appointment Notification',
        };

        return new Envelope(
            subject: $subject . ': ' . $this->appointment->subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // ✅ Handle business logo properly
        $businessLogo = $this->businessInfo['company_logo'] ?? 'logo.png';

        // If full URL was provided, convert to filename
        if (str_starts_with($businessLogo, 'http')) {
            // Try to extract the file name (for example: logo.png)
            $fileName = basename(parse_url($businessLogo, PHP_URL_PATH));
            $localPath = public_path($fileName);
        } else {
            // If it's already a relative path or stored in /public
            $localPath = public_path($businessLogo);
        }

        // ✅ Prepare URLs and paths
        $businessLogoUrl = asset($businessLogo);
        $businessLogoPath = file_exists($localPath) ? $localPath : public_path('logo.png');

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
                'action' => $this->action,
                'assigneeName' => $this->appointment->assignedFor->name ?? 'User',
                'business_info' => [
                    'company_name'   => $this->businessInfo['company_name'] ?? '',
                    'email'  => $this->businessInfo['email'] ?? '',
                    'phone_number'  => $this->businessInfo['phone_number'] ?? '',
                    'address_line_1'  => $this->businessInfo['address_line_1'] ?? '',
                    'business_logo_url'  => $businessLogoUrl,  // For production (external)
                    'business_logo_path' => $businessLogoPath, // For local embed
                ],
            ],
        );
    }
}
