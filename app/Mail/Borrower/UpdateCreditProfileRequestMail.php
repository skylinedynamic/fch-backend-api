<?php

namespace App\Mail\Borrower;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\User\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UpdateCreditProfileRequestMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $user;
    public $creditRequest;
    protected array $dynamicData = [];

    public function __construct(User $user, ?CreditProfileRequest $creditRequest = null, ?int $partnerId = null)
    {
        $this->user = $user;
        $this->creditRequest = $creditRequest;
        $this->setMailPartnerId($partnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: 'Request to update credit profile data',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.update_credit_profile_request',
            with: [
                'user' => $this->user,
                'creditRequest' => $this->creditRequest,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
            ],
        );
    }
}
