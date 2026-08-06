<?php

namespace App\Mail\FundingRequest;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\FundingRequest\FundingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use App\Models\Partner\Partner;
use App\Models\Common\AppConfiguration;

class PartnerFundingRequestApproved extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $fundingRequest;

    public function __construct(FundingRequest $fundingRequest)
    {
        $this->fundingRequest = $fundingRequest;
        $this->fundingRequest->loadMissing(['borrower.user', 'partner', 'fundingSequence']);
        $hostPartner = Partner::host() ?? Partner::where('is_host', true)->first();
        if ($hostPartner) {
            $this->mailPartnerId = $hostPartner->id;
        } else {
            $this->setMailPartnerId();
        }
    }

    public function build()
    {
        try {
            $branding = $this->getHostBrandingData();

            return $this->subject("Funding Request Approved – Borrower Ready for Funding Application")
                        ->view('emails.partner.funding_request_approved')
                        ->with([
                            'fundingRequest' => $this->fundingRequest,
                            'siteName'       => $branding['siteName'],
                            'logoUrl'        => $branding['logoUrl'],
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building PartnerFundingRequestApproved email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
