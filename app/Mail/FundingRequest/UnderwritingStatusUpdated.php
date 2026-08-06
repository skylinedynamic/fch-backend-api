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

use App\Models\Common\AppConfiguration;

class UnderwritingStatusUpdated extends Mailable
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
            $status = $this->fundingRequest->status;
            $partner = $this->fundingRequest->partner ?? $this->fundingRequest->borrower?->partner;
            $partnerId = ($partner && !$partner->is_host && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL)
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

            return $this->subject("Funding Request {$status}")
                        ->view('emails.borrower.underwriting_status_updated')
                        ->with([
                            'fundingRequest' => $this->fundingRequest,
                            'siteName'       => $siteName,
                            'logoUrl'        => $logoUrl,
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building UnderwritingStatusUpdated email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
