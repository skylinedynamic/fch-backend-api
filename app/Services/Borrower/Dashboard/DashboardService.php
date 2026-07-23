<?php


namespace App\Services\Borrower\Dashboard;

use App\Http\Resources\Borrower\Dashboard\DashboardAppointmentResource;
use App\Http\Resources\Borrower\Dashboard\DashboardFundingApplicationResource;
use App\Http\Resources\Borrower\Dashboard\DashboardInvoiceResource;
use App\Http\Resources\Borrower\Dashboard\DashboardPreQualificationResource;
use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
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
            ->first();

        if (!$borrower) {
             throw new \Exception(__('borrower.borrower_not_found'));
        }

        $data = [];

        // Counts
        $data['states'] = [
            'funding_applications' => $borrower->fundingApplications->where('status','!=',FundingApplication::ON_HOLD)->count(),
            'pre_qualifications' => $borrower->preQualificationRequests->where('status','!=',PreQualificationRequest::ON_HOLD)->count(),
            'total_requested_amount' => $borrower->fundingApplications->sum('requested_amount'),
            'total_funded_amount' => $borrower->fundingApplications->sum('total_funded_amount'),
        ];
        
        // Latest Funding Applications
        $data['latest_funding_applications'] = DashboardFundingApplicationResource::collection(
            $borrower->fundingApplications()
                ->where('status','!=',FundingApplication::ON_HOLD)
                ->latest()
                ->limit(5)
                ->get()
        );

        // Latest Pre-Qualifications
        $data['latest_pre_qualifications'] = DashboardPreQualificationResource::collection(
            $borrower->preQualificationRequests()
                ->where('status','!=',PreQualificationRequest::ON_HOLD)
                ->latest()
                ->limit(5)
                ->get()
        );
            
        // Upcoming Meeting
        $data['upcoming_meeting'] = DashboardAppointmentResource::collection(
            Appointment::with(['scheduledBy:id,name', 'assignedFor:id,name'])
                ->where('appointment_date', '>=', now())
                ->where(function ($q) use ($user) {
                    $q->where('scheduled_by', $user->id)
                      ->orWhere('assigned_for', $user->id);
                })
                ->orderBy('appointment_date', 'asc')
                ->limit(5)
                ->get()
        );

        // Latest Unpaid Invoices
        $isPartnerType = $borrower?->partner?->is_host || ($borrower?->partner?->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL) || ($borrower?->partner?->partner_type === Partner::PARTNER_TYPE_CERTIFIED);
        if ($isPartnerType) {
            $data['invoices'] = DashboardInvoiceResource::collection(
                $borrower->invoices()
                    ->where('is_partner_paying', false)
                    ->latest()
                    ->limit(5)
                    ->get()
            );
        } else {
            $data['invoices'] = [];
        }

        $tier = $borrower->tier;
        $data['tier'] = $tier ? [
            'id' => $tier->id,
            'name' => $tier->name,
            'title' => $tier->title,
            'tasks_under_review' => $borrower->taskUnderReview()->count(),
        ] : null;

        $data['fico_score'] = $borrower->credit_profile_updated ? $borrower->fico_score : null;

        return $data;
    }
}
