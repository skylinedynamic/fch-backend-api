<?php

namespace App\Mail\FundingRequest;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\FundingRequest\FundingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use App\Models\Partner\Partner;
use App\Models\Common\AppConfiguration;

class PartnerFundingApplicationReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $fundingRequest;
    public $recipientPartner;
    public $recipientName;
    public $isHostRecipient;

    public function __construct(FundingRequest $fundingRequest, $recipientPartner = null, bool $isHostRecipient = false)
    {
        $this->fundingRequest = $fundingRequest;
        $this->fundingRequest->loadMissing(['borrower.user', 'partner', 'fundingSequence']);
        
        $hostPartner = Partner::host() ?? Partner::where('is_host', true)->first();
        
        $this->isHostRecipient = $isHostRecipient || ($recipientPartner && $recipientPartner->is_host);
        $this->recipientPartner = $recipientPartner ?? ($this->isHostRecipient ? $hostPartner : $this->fundingRequest->partner);

        if ($this->isHostRecipient) {
            $this->mailPartnerId = $hostPartner ? $hostPartner->id : 1;
            $this->recipientName = $this->recipientPartner?->contact_person 
                ?? $this->recipientPartner?->company_name 
                ?? $hostPartner?->contact_person 
                ?? $hostPartner?->company_name 
                ?? 'Host Contact';
        } else {
            $partner = $this->recipientPartner ?? $fundingRequest->partner ?? $fundingRequest->borrower?->partner;
            if ($partner && !$partner->is_host && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL) {
                $this->mailPartnerId = $partner->id;
            } elseif ($partner) {
                $this->mailPartnerId = $partner->id;
            } else {
                $this->setMailPartnerId();
            }
            $this->recipientName = $partner?->contact_person 
                ?? $partner?->company_name 
                ?? 'Partner';
        }
    }

    protected function getBrandingData(): array
    {
        $partnerId = $this->mailPartnerId ?: (getConfigPartner() ? getConfigPartner()->id : null);

        $brandingConfig = AppConfiguration::where('partner_id', $partnerId)
            ->where('module', AppConfiguration::MODULE_BRANDING)
            ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo'])
            ->pluck('value', 'key')
            ->toArray();

        $partner = $partnerId ? Partner::find($partnerId) : Partner::where('is_host', true)->first();

        return [
            'siteName' => $brandingConfig['site_name'] ?? ($partner ? $partner->company_name : config('app.name')),
            'logoUrl' => (isset($brandingConfig['secondary_logo']) && $brandingConfig['secondary_logo'] != null ) 
                ? asset('storage/' . $brandingConfig['secondary_logo']) 
                : (isset($brandingConfig['primary_logo']) ? asset('storage/' . $brandingConfig['primary_logo']) : null),
        ];
    }

    public function build()
    {
        try {
            $branding = $this->isHostRecipient ? $this->getHostBrandingData() : $this->getBrandingData();

            return $this->subject("Reminder: Borrower Has Not Started Funding Application")
                        ->view('emails.partner.funding_application_reminder')
                        ->with([
                            'fundingRequest'   => $this->fundingRequest,
                            'recipientPartner' => $this->recipientPartner,
                            'recipientName'    => $this->recipientName,
                            'isHostRecipient'  => $this->isHostRecipient,
                            'siteName'         => $branding['siteName'],
                            'logoUrl'          => $branding['logoUrl'],
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building PartnerFundingApplicationReminder email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
