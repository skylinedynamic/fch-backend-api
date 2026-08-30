<?php

namespace App\Mail\FundingRequest;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UnderwritingSubmitted extends Mailable
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
            $branding = $this->getHostBrandingData();
            Log::info('Branding', [$branding]);

            return $this->subject('New Funding Request Submitted')
                        ->view('emails.partner.underwriting_submitted')
                        ->with([
                            'siteName'    => $branding['siteName'],
                            'logoUrl'     => $branding['logoUrl'],
                            'partnerName' => $this->fundingRequest->partner->contact_person
                                            ?? $this->fundingRequest->partner->full_name
                                            ?? $this->fundingRequest->partner->company_name
                                            ?? 'Partner',
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building UnderwritingSubmitted email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
