<?php

namespace App\Mail\FundingReview;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\FundingReview\FundingReview;

class UnderwritingSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public $fundingReview;

    public function __construct(FundingReview $fundingReview)
    {
        $this->fundingReview = $fundingReview;
    }

    public function build()
    {
        return $this->subject('New Underwriting Review Submitted')
                    ->view('emails.partner.underwriting_submitted');
    }
}
