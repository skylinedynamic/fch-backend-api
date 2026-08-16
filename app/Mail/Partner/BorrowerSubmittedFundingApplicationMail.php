<?php

namespace App\Mail\Partner;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Borrower\FundingApplication;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BorrowerSubmittedFundingApplicationMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $application;
    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(FundingApplication $application, $mailPartnerId = null)
    {
        $this->application = $application;
        $this->mailPartnerId = $mailPartnerId;
        if (!$this->mailPartnerId) {
            $partner = $application->partner ?? $application->borrower?->partner;
            if ($partner && $partner->partner_type === \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL) {
                $this->mailPartnerId = $partner->id;
            } else {
                $this->setMailPartnerId();
            }
        }
    }

    protected function getBrandingData(): array
    {
        $partnerId = $this->mailPartnerId ?: getConfigPartner()->id;

        $brandingConfig = AppConfiguration::where('partner_id', $partnerId)
            ->where('module', AppConfiguration::MODULE_BRANDING)
            ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo'])
            ->pluck('value', 'key')
            ->toArray();

        $partner = $partnerId ? Partner::find($partnerId) : \App\Models\Partner\Partner::where('is_host', true)->first();

        return [
            'siteName' => $brandingConfig['site_name'] ?? ($partner ? $partner->company_name : config('app.name')),
            'logoUrl' => (isset($brandingConfig['secondary_logo']) && $brandingConfig['secondary_logo'] != null ) 
                ? asset('storage/' . $brandingConfig['secondary_logo']) 
                : (isset($brandingConfig['primary_logo']) ? asset('storage/' . $brandingConfig['primary_logo']) : null),
        ];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        $this->dynamicData['applicationUrl'] = concatUrl(getPartnerDomain($this->mailPartnerId), 'intake-review/funding-application/' . $this->application->id, config('app.frontend_partner_url'));

        $borrowerName = $this->application->borrower ? trim("{$this->application->borrower->first_name} {$this->application->borrower->last_name}") : null;
        $clientName = $this->application->full_name ?: ($borrowerName ?: 'Client');

        return new Envelope(
            subject: 'New Funding Application Submitted by - ' . $clientName,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.partner.borrower_submitted_funding_application',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'applicationUrl' => $this->dynamicData['applicationUrl'],
                'application' => $this->application,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
