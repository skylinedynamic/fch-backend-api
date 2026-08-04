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
        $partner = $fundingRequest->partner ?? $fundingRequest->borrower?->partner;
        if ($partner && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL) {
            $this->mailPartnerId = $partner->id;
        } else {
            $this->setMailPartnerId();
        }
    }

    public function build()
    {
        try {
            $partner = $this->fundingRequest->partner ?? $this->fundingRequest->borrower?->partner;
            $partnerId = ($partner && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL)
                ? $partner->id
                : getConfigPartner()->id;

            $brandingConfig = AppConfiguration::where('partner_id', $partnerId)
                ->where('module', AppConfiguration::MODULE_BRANDING)
                ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo'])
                ->pluck('value', 'key')
                ->toArray();

            $siteName = $brandingConfig['site_name'] ?? ($partner?->company_name ?? config('app.name'));
            $logoUrl = (isset($brandingConfig['secondary_logo']) && $brandingConfig['secondary_logo'] != null)
                ? asset('storage/' . $brandingConfig['secondary_logo'])
                : (isset($brandingConfig['primary_logo']) ? asset('storage/' . $brandingConfig['primary_logo']) : null);

            $clientName = $this->fundingRequest->borrower->full_name 
                ?? ($this->fundingRequest->borrower->first_name . ' ' . $this->fundingRequest->borrower->last_name);

            return $this->subject("Funding Request Approved – Borrower Ready for Funding Application")
                        ->view('emails.partner.funding_request_approved')
                        ->with([
                            'fundingRequest' => $this->fundingRequest,
                            'siteName'       => $siteName,
                            'logoUrl'        => $logoUrl,
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building PartnerFundingRequestApproved email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
