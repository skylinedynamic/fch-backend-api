<?php

namespace App\Mail\FundingReview;

use App\Mail\Traits\MailableBranding;
use App\Models\FundingReview\FundingReview;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UnderwritingSubmitted extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    public $fundingReview;

    public function __construct(FundingReview $fundingReview)
    {
        $this->fundingReview = $fundingReview;
    }

    public function build()
    {
        $branding = $this->getHostBrandingData();
        Log::info($branding);
        Log::info('Host Email');
        return $this->subject('New Underwriting Review Submitted')
                    ->view('emails.partner.underwriting_submitted')
                    ->with([
                        'siteName' => $branding['siteName'],
                        'logoUrl'  => $branding['logoUrl'],
                    ]);
    }
}
