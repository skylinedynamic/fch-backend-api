<?php

namespace App\Mail\Borrower;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BorrowerDocumentsUploadedMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    public $user;
    public $documents;

    /**
     * Create a new message instance.
     */
    public function __construct($user, $documents)
    {
        $this->user = $user;
        $this->documents = collect($documents);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Documents Uploaded Successfully',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.documents_uploaded',
            with: array_merge([

                'user' => $this->user,
                'documents' => $this->documents,
            
            ], $this->getBrandingData()),
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->documents as $document) {
            // Get the raw relative path stored in DB
            $filePath = $document->getRawOriginal('file_path');
            
            // Construct the path relative to the disk root
            // The disk is 'public', and files are in borrowers/{id}/
            $diskPath = "borrowers/{$document->borrower_id}/{$filePath}";

            $attachments[] = \Illuminate\Mail\Mailables\Attachment::fromStorageDisk('public', $diskPath)
                ->as($document->file_name);
        }

        return $attachments;
    }
}
