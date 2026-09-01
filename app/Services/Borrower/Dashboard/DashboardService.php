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
use App\Models\FundingRequest\FundingRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            'funding_requests' => $borrower->fundingRequests->where('status','!=', \App\Models\FundingRequest\FundingRequest::STATUS_CANCELED)->count(),
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
                    ->where('entity_type','=',Borrower::class)
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

        $data['fico_score'] = $borrower->credit_profile_updated ? ($borrower->fico_score) : null;

        // Fetch the latest active approved funding request (Not Started Funding Application)
        $activeApprovedRequestsQuery = FundingRequest::with('fundingSequence')
            ->where('borrower_id', $borrower->id)
            ->where('status', FundingRequest::STATUS_APPROVED)
            ->whereNotExists(function ($query) use ($borrower) {
                $query->select(DB::raw(1))
                      ->from('funding_applications')
                      ->where('funding_applications.borrower_id', $borrower->id)
                      ->where(function ($sub) {
                          $sub->whereColumn('funding_applications.funding_request_id', 'funding_requests.id')
                              ->orWhere(function ($s2) {
                                  $s2->whereNotNull('funding_requests.funding_sequence_id')
                                     ->whereColumn('funding_applications.funding_sequence_id', 'funding_requests.funding_sequence_id');
                              });
                      })
                      ->whereNull('deleted_at');
            });

        $activeApprovedRequestCount = $activeApprovedRequestsQuery->count();
        $activeApprovedRequests = $activeApprovedRequestsQuery->latest()->orderBy('id', 'desc')->get();

        if ($activeApprovedRequests->isNotEmpty()) {
            $data['active_approved_funding_requests'] = $activeApprovedRequests->map(function ($req) {
                $estimatedRange = formatAmountRange($req->fundingSequence?->amount_range) 
                    ?? ($req->requested_amount ? currencyFormat($req->requested_amount, 0) : null);

                return [
                    'id' => $req->id,
                    'requested_amount' => $req->requested_amount,
                    'estimated_funding_range' => $estimatedRange,
                    'funding_type' => $req->funding_type,
                ];
            })->toArray();
            
            // For backward compatibility / quick access to the latest one
            $data['active_approved_funding_request'] = $data['active_approved_funding_requests'][0];
            $data['active_approved_funding_request_count'] = $activeApprovedRequestCount;
        } else {
            $data['active_approved_funding_requests'] = [];
            $data['active_approved_funding_request'] = null;
            $data['active_approved_funding_request_count'] = 0;
        }

        return $data;
    }
}
