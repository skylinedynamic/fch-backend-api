<?php

namespace App\Mail\FundingReview;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\FundingReview\FundingReview;
use App\Mail\Traits\MailableBranding;

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
        return $this->subject('New Underwriting Review Submitted')
                    ->view('emails.partner.underwriting_submitted')
                    ->with([
                        'siteName' => $branding['siteName'],
                        'logoUrl'  => $branding['logoUrl'],
                    ]);
    }
}
