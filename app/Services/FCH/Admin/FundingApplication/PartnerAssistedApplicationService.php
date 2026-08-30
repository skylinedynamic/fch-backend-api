<?php

namespace App\Services\FCH\Admin\FundingApplication;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class PartnerAssistedApplicationService
{
    /**
     * Start a partner-assisted application
     */
    public function startAssistedApplication($fundingRequestId, $partnerUserId)
    {
        $fundingRequest = FundingRequest::with('borrower')->findOrFail($fundingRequestId);

        // Check if application already exists
        if ($fundingRequest->fundingApplication) {
            return $fundingRequest->fundingApplication;
        }

        // Create new draft
        $application = new FundingApplication();
        $application->borrower_id = $fundingRequest->borrower_id;
        $application->partner_id = $fundingRequest->partner_id ?? $fundingRequest->borrower->partner_id;
        $application->funding_request_id = $fundingRequest->id;
        $application->status = FundingApplication::DRAFT;
        $application->partner_prepared_by = $partnerUserId;
        $application->save();

        activity()
            ->performedOn($application)
            ->causedBy(auth()->user())
            ->log('Started Partner-Assisted Application Draft');

        return $application;
    }

    /**
     * Send to borrower for review
     */
    public function sendToBorrower($applicationId)
    {
        $application = FundingApplication::findOrFail($applicationId);
        
        $application->status = FundingApplication::SENT_TO_BORROWER;
        $application->save();

        activity()
            ->performedOn($application)
            ->causedBy(auth()->user())
            ->log('Sent application to borrower for review');

        // Trigger email here
        // Mail::to(...)->queue(new PartnerPreparedApplicationMail(...));

        return $application;
    }
}
