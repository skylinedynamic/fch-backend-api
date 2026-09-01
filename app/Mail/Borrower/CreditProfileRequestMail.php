<?php

namespace App\Mail\Borrower;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Borrower\CreditProfileRequest;

class CreditProfileRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $creditProfileRequest;
    protected array $dynamicData = [];

    public function __construct(CreditProfileRequest $creditProfileRequest, ?int $partnerId = null)
    {
        $this->creditProfileRequest = $creditProfileRequest;
        $this->setMailPartnerId($partnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: 'Credit profile information updated for iSoftPull data',
        );
    }

    public function content(): Content
    {
        // concatUrl(), not a raw rtrim() of the config value: BASE_PARTNER_PORTAL
        // (e.g. "localhost:5174") commonly has no http(s):// scheme. Without it,
        // "<a href=\"localhost:5174/...\">" is a RELATIVE link - browsers/mail
        // clients resolve it against the webmail's own domain instead of
        // navigating to the admin app, which is exactly why the button did
        // nothing when clicked. This mailable is always sent to the host partner
        // (see CreditProfileRequestController::store()), so the link always goes
        // to the shared admin app - no per-partner domain to resolve here.
        $requestUrl = concatUrl(
            null,
            'intake-review/credit-profile-requests/' . $this->creditProfileRequest->id,
            config('app.frontend_partner_url')
        );

        return new Content(
            view: 'emails.borrower.credit_profile_request',
            with: [
                'creditRequest' => $this->creditProfileRequest,
                'requestUrl' => $requestUrl,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
            ],
        );
    }
}
