<?php

namespace App\Mail\ActivityLog;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Http\UploadedFile;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Support\Mail\RecipientName;

class ActivityLogMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * The files to attach, read when the mail is built.
     *
     * The request's UploadedFiles are temp files deleted when the request ends,
     * long before a queue worker sends this, and cannot be serialized into the
     * job anyway. Contents are base64 so the job payload stays valid JSON.
     *
     * @var array<int, array{name: string, mime: ?string, content: string}>
     */
    protected array $files = [];

    /**
     * @param array $emailData     Keys: subject, body (HTML string)
     * @param array $attachmentNames  List of original file names (for display in email body)
     * @param array $uploadedFiles UploadedFile instances to physically attach
     */
    public function __construct(
        protected array $emailData,
        protected array $attachmentNames,
        array $uploadedFiles
    ) {
        $this->setMailPartnerId();

        foreach ($uploadedFiles as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $this->files[] = [
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                    'content' => base64_encode((string) file_get_contents($file->getRealPath())),
                ];
            }
        }
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();

        $this->dynamicData['siteName'] = $branding['siteName'] ?? config('app.name');
        $this->dynamicData['logoUrl']  = $branding['logoUrl'] ?? '';

        return new Envelope(
            subject: $this->emailData['subject'] ?? __('activity_log.mail.default_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.activity_log_mail',
            with: [
                'subject'           => $this->emailData['subject'] ?? __('activity_log.mail.default_subject'),
                // Greeted by name when the address is someone's in the system.
                'recipientName'     => RecipientName::for($this->to[0]['address'] ?? null),
                'body'              => $this->emailData['body'] ?? '',
                'attachments_count' => count($this->files),
                'attachment_names'  => $this->attachmentNames,
                'siteName'          => $this->dynamicData['siteName'],
                'logoUrl'           => $this->dynamicData['logoUrl'],
            ],
        );
    }

    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromData(fn () => base64_decode($file['content']), $file['name'])
                ->withMime($file['mime'] ?? 'application/octet-stream'),
            $this->files
        );
    }
}
