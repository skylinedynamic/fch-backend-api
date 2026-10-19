<?php

namespace App\Mail\Partner;

use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued. It already pulled in Queueable but never declared ShouldQueue, so it
 * was sending inline - holding the borrower's submission open on an SMTP round
 * trip, and swallowing a transient refusal instead of retrying it.
 */
class NewChangeRequestSubmittedMail extends Mailable implements ShouldQueue
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
        // concatUrl() supplies the scheme. BASE_PARTNER_PORTAL is stored without
        // one ("localhost:5174"), and a href with no scheme is read as a
        // relative path, so the button in the mail went nowhere.
        $this->dynamicData['reviewUrl'] = concatUrl(
            getPartnerDomain($this->partner->id),
            'intake-review/change-requests',
            config('app.frontend_partner_url')
        );
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
                // An SSN or EIN masked to its last four; the full values are on
                // the change request screen, behind the admin sign-in.
                'oldValue'      => $this->changeRequest->maskedValue($this->changeRequest->old_value) ?? '',
                'newValue'      => $this->changeRequest->maskedValue($this->changeRequest->new_value),
                'siteName'      => $this->dynamicData['siteName'],
                'logoUrl'       => $this->dynamicData['logoUrl'],
                'reviewUrl'     => $this->dynamicData['reviewUrl'],
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
