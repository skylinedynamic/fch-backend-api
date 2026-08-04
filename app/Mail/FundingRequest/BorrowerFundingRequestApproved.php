<?php

namespace App\Mail\FundingRequest;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\FundingRequest\FundingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BorrowerFundingRequestApproved extends Mailable
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

            return $this->subject('Your Funding Request Has Been Approved – Complete Your Funding Application')
                        ->view('emails.borrower.funding_request_approved')
                        ->with([
                            'siteName' => $branding['siteName'],
                            'logoUrl'  => $branding['logoUrl'],
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building BorrowerFundingRequestApproved email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
