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

class BorrowerFundingRequestApproved extends Mailable
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

    protected function getBrandingData(): array
    {
        $partnerId = $this->mailPartnerId ?: getConfigPartner()->id;

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
            $branding = $this->getBrandingData();

            return $this->subject('Your Funding Request Has Been Approved – Complete Your Funding Application')
                        ->view('emails.borrower.funding_request_approved')
                        ->with([
                            'fundingRequest' => $this->fundingRequest,
                            'siteName'       => $branding['siteName'],
                            'logoUrl'        => $branding['logoUrl'],
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building BorrowerFundingRequestApproved email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
