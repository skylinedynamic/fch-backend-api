<?php

namespace App\Mail\ActivityLog;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Http\UploadedFile;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;

class ActivityLogMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * @param array $emailData     Keys: subject, body (HTML string)
     * @param array $attachmentNames  List of original file names (for display in email body)
     * @param array $uploadedFiles UploadedFile instances to physically attach
     */
    public function __construct(
        protected array $emailData,
        protected array $attachmentNames,
        protected array $uploadedFiles
    ) {
        $this->setMailPartnerId();
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();

        $this->dynamicData['siteName'] = $branding['siteName'] ?? config('app.name');
        $this->dynamicData['logoUrl']  = $branding['logoUrl'] ?? '';

        return new Envelope(
            subject: $this->emailData['subject'] ?? 'New Email from Activity Log',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.activity_log_mail',
            with: [
                'subject'           => $this->emailData['subject'] ?? 'New Email from Activity Log',
                'body'              => $this->emailData['body'] ?? '',
                'attachments_count' => count($this->uploadedFiles),
                'attachment_names'  => $this->attachmentNames,
                'siteName'          => $this->dynamicData['siteName'],
                'logoUrl'           => $this->dynamicData['logoUrl'],
            ],
        );
    }

    public function attachments(): array
    {
        $result = [];

        foreach ($this->uploadedFiles as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $result[] = Attachment::fromPath($file->getRealPath())
                    ->as($file->getClientOriginalName())
                    ->withMime($file->getMimeType());
            }
        }

        return $result;
    }
}
