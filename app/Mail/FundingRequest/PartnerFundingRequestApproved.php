<?php

namespace App\Mail\FundingRequest;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\FundingRequest\FundingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PartnerFundingRequestApproved extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $fundingRequest;

    public function __construct(FundingRequest $fundingRequest)
    {
        $this->fundingRequest = $fundingRequest;
        $this->setMailPartnerId();
    }

    public function build()
    {
        try {
            $branding = $this->getBrandingData();
            $clientName = $this->fundingRequest->borrower->full_name 
                ?? ($this->fundingRequest->borrower->first_name . ' ' . $this->fundingRequest->borrower->last_name);

            return $this->subject("Funding Request Approved – Borrower Ready for Funding Application")
                        ->view('emails.partner.funding_request_approved')
                        ->with([
                            'siteName' => $branding['siteName'],
                            'logoUrl'  => $branding['logoUrl'],
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building PartnerFundingRequestApproved email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
