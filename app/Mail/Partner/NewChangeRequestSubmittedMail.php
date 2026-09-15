<?php

namespace App\Mail\Partner;

use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewChangeRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public BorrowerChangeRequest $changeRequest;
    protected array $dynamicData = [];
    public Partner $partner;

    /**
     * Create a new message instance.
     */
    public function __construct(Partner $partner ,BorrowerChangeRequest $changeRequest)
    {
        $this->partner = $partner;
        $this->changeRequest = $changeRequest;
        $this->mailPartnerId = $partner->id;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getHostBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: __('borrower.change_request_submitted_subject'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $borrower = $this->changeRequest->borrower;
        $borrowerName = $borrower ? personName($borrower->first_name ?? null, $borrower->middle_name ?? null, $borrower->last_name ?? null) : '';
        $businessName = $borrower ? ($borrower->business_name ?? '') : '';

        return new Content(
            view: 'emails.partner.new_change_request_submitted',
            with: [
                'partnerName'   => $this->partner->full_name ?? __('borrower.host_partner'),
                'changeRequest' => $this->changeRequest,
                'borrowerName'  => !empty($borrowerName) ? $borrowerName : '',
                'businessName'  => $businessName,
                'fieldName'     => $this->changeRequest->visible_field_name ?? $this->changeRequest->field_name,
                'oldValue'      => $this->changeRequest->old_value ?? '',
                'newValue'      => $this->changeRequest->new_value,
                'siteName'      => $this->dynamicData['siteName'],
                'logoUrl'       => $this->dynamicData['logoUrl'],
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array
     */
    public function attachments(): array
    {
        return [];
    }
}
