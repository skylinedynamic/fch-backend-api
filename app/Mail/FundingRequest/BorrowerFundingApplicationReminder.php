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

class BorrowerFundingApplicationReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $fundingRequest;

    public function __construct(FundingRequest $fundingRequest)
    {
        $this->fundingRequest = $fundingRequest;
        $this->fundingRequest->loadMissing(['borrower.user', 'partner', 'fundingSequence']);
        $partner = $fundingRequest->partner ?? $fundingRequest->borrower?->partner;
        if ($partner && !$partner->is_host && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL) {
            $this->mailPartnerId = $partner->id;
        } else {
            $this->setMailPartnerId();
        }
    }

    public function build()
    {
        try {
            $branding = $this->getBrandingData();

            return $this->subject('Reminder: Your Funding Request Has Been Approved – Complete Your Funding Application')
                        ->view('emails.borrower.funding_application_reminder')
                        ->with([
                            'fundingRequest' => $this->fundingRequest,
                            'siteName'       => $branding['siteName'],
                            'logoUrl'        => $branding['logoUrl'],
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building BorrowerFundingApplicationReminder email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
