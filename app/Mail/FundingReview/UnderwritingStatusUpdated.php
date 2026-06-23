<?php

namespace App\Mail\FundingReview;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\FundingReview\FundingReview;
use App\Mail\Traits\MailableBranding;

class UnderwritingStatusUpdated extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    public $fundingReview;

    public function __construct(FundingReview $fundingReview)
    {
        $this->fundingReview = $fundingReview;
    }

    public function build()
    {
        $status = $this->fundingReview->status;
        $branding = $this->getBrandingData();
        return $this->subject("Underwriting Review {$status}")
                    ->view('emails.borrower.underwriting_status_updated')
                    ->with([
                        'siteName' => $branding['siteName'],
                        'logoUrl'  => $branding['logoUrl'],
                    ]);
    }
}
