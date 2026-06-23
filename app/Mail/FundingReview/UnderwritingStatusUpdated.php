<?php

namespace App\Mail\FundingReview;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\FundingReview\FundingReview;

class UnderwritingStatusUpdated extends Mailable
{
    use Queueable, SerializesModels;

    public $fundingReview;

    public function __construct(FundingReview $fundingReview)
    {
        $this->fundingReview = $fundingReview;
    }

    public function build()
    {
        $status = $this->fundingReview->status;
        return $this->subject("Underwriting Review {$status}")
                    ->view('emails.borrower.underwriting_status_updated');
    }
}
