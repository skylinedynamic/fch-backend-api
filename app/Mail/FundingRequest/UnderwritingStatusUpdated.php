<?php

namespace App\Mail\FundingRequest;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\FundingRequest\FundingRequest;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Log;

use App\Models\Common\AppConfiguration;
use App\Services\FCH\Common\Setting\AppConfigurationService;

class UnderwritingStatusUpdated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    /** Sent to the borrower: the button opens the request in the borrower portal. */
    public const AUDIENCE_BORROWER = 'borrower';

    /** Sent to the request's non-host partner: the button opens it in the partner portal. */
    public const AUDIENCE_PARTNER = 'partner';

    public $fundingRequest;

    public string $audience;

    public function __construct(FundingRequest $fundingRequest, string $audience = self::AUDIENCE_BORROWER)
    {
        $this->fundingRequest = $fundingRequest;
        $this->audience = $audience;
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

            // The partner the constructor resolved, not getConfigPartner() again:
            // this is rendered by a queue worker, where there is no authenticated
            // user or request Origin and that helper answers "host" for everyone.
            $brandingConfig = app(AppConfigurationService::class)->getBrandingSettings($this->mailPartnerId);

            $siteName = $brandingConfig['site_name'] ?? ($partner?->company_name ?? config('app.name'));
            // Mail headers are white, so the mail logo first, then the other
            // light-surface ones - never the dark sidebar logo.
            $logoPath = $brandingConfig['logo_mail'] ?? $brandingConfig['logo_document'] ?? null;
            $logoUrl = $logoPath ? asset('storage/' . $logoPath) : null;

            return $this->subject("Funding Request {$status}")
                        ->view('emails.borrower.underwriting_status_updated')
                        ->with([
                            'fundingRequest' => $this->fundingRequest,
                            'siteName'       => $siteName,
                            'logoUrl'        => $logoUrl,
                            'detailsUrl'     => $this->detailsUrl(),
                        ]);
        } catch (\Exception $e) {
            Log::error('Error building UnderwritingStatusUpdated email: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * The request itself, in the portal of whoever reads this copy.
     */
    private function detailsUrl(): string
    {
        $id = $this->fundingRequest->id;
        $partnerId = $this->fundingRequest->partner_id;

        if ($this->audience === self::AUDIENCE_PARTNER) {
            return concatUrl(getPartnerDomain($partnerId), 'intake-review/funding-request/' . $id);
        }

        return concatUrl(getBorrowerDomain($partnerId), 'funding-requests/' . $id, config('app.frontend_borrower_url'));
    }
}
