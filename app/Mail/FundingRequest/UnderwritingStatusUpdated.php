<?php

namespace App\Mail\FundingRequest;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\FundingRequest\FundingRequest;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Log;

class UnderwritingStatusUpdated extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $fundingRequest;

    public function __construct(FundingRequest $fundingRequest)
    {
        $this->fundingRequest = $fundingRequest;
        
        $hostPartner = Partner::where('is_host', true)->first();
        if ($hostPartner) {
            $this->mailPartnerId = $hostPartner->id;
        }
    }

    public function build()
    {
        try {
            $status = $this->fundingRequest->status;
            $branding = $this->getBrandingData();
            
            Log::info('Branding', [$branding]);

            return $this->subject("Funding Request {$status}")
                        ->view('emails.borrower.underwriting_status_updated')
                        ->with([
                            'siteName' => $branding['siteName'],
                            'logoUrl'  => $branding['logoUrl'],
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building UnderwritingStatusUpdated email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
