<?php

namespace App\Services\Borrower\Dashboard;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Invoice\Invoice;
use Illuminate\Support\Facades\Auth;

class DashboardService
{
    /**
     * Get Dashboard Stats for Borrower
     */
    public function getStats()
    {
        $user = Auth::user();
       

        // Get Borrower Profile
        $borrower = Borrower::where('id', $user->entity_id)
        ->where('partner_id', $user->partner_id)->first();

        if (!$borrower) {
             throw new \Exception(__('borrower.borrower_not_found'));
        }

        $borrowerId = $borrower->id;

        $data = [];

        // Counts
        $data['counts'] = [
            'total_invoices' => Invoice::where('borrower_id', $borrowerId)->count(),
            'unpaid_invoices' => Invoice::where('borrower_id', $borrowerId)->where('status', Invoice::STATUS_UNPAID)->count(),
            'funding_applications' => FundingApplication::where('borrower_id', $borrowerId)->count(),
            'pre_qualifications' => PreQualificationRequest::where('borrower_id', $borrowerId)->count(),
            // Add other counts as needed, e.g. Credit Builder Task progress
        ];

        // Lists
        
        // Latest Funding Applications
        $data['latest_funding_applications'] = FundingApplication::where('borrower_id', $borrowerId)
            ->latest()
            ->take(5)
            ->get();

        // Latest Pre-Qualifications
        $data['latest_pre_qualifications'] = PreQualificationRequest::where('borrower_id', $borrowerId)
            ->latest()
            ->take(5)
            ->get();
            
        // Latest Invoices
        $data['latest_invoices'] = Invoice::where('borrower_id', $borrowerId)
            ->latest()
            ->take(5)
            ->get();

        return $data;
    }
}
