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
        $borrower = $this->currentBorrower();

        $data = [];

        // Counts
        $totalRequested = $borrower->fundingApplications->sum('requested_amount');
        $totalFunded = $borrower->fundingApplications->sum('total_funded_amount');
        $data['states'] = [
            'funding_applications' => $borrower->fundingApplications->where('status','!=',FundingApplication::ON_HOLD)->count(),
            'funding_requests' => $borrower->fundingRequests->where('status','!=', \App\Models\FundingRequest\FundingRequest::STATUS_CANCELED)->count(),
            'total_requested_amount' => $totalRequested,
            'total_funded_amount' => $totalFunded,
            // As the top cards show them: $37.2M, $45,000.
            'total_requested_amount_formatted' => formatCompactCurrency($totalRequested),
            'total_funded_amount_formatted' => formatCompactCurrency($totalFunded),
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

        // The approved requests not yet started come from
        // getActiveApprovedFundingRequests(): the portal loads them on every page.

        return $data;
    }

    /**
     * The signed-in borrower's approved funding requests that no application
     * has been started for: what the layout's banner and sidebar badge show on
     * every page, without loading the rest of the dashboard.
     */
    public function getActiveApprovedFundingRequests(): array
    {
        return $this->activeApprovedFundingRequests($this->currentBorrower());
    }

    private function currentBorrower(): Borrower
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();

        if (!$borrower) {
            throw new \Exception(__('borrower.borrower_not_found'));
        }

        return $borrower;
    }

    /**
     * Approved requests with no application on them (Not Started), latest first.
     */
    private function activeApprovedFundingRequests(Borrower $borrower): array
    {
        $activeApprovedRequestsQuery = FundingRequest::with('fundingSequence')
            ->where('borrower_id', $borrower->id)
            ->where('status', FundingRequest::STATUS_APPROVED)
            ->whereNotExists(function ($query) use ($borrower) {
                $query->select(DB::raw(1))
                      ->from('funding_applications')
                      ->where('funding_applications.borrower_id', $borrower->id)
                      // Started = an application on this request. An application
                      // on another (older) request for the same option does not
                      // count - every request is a new one. Only an application
                      // linked to no request falls back to matching the option.
                      ->where(function ($sub) {
                          $sub->whereColumn('funding_applications.funding_request_id', 'funding_requests.id')
                              ->orWhere(function ($s2) {
                                  $s2->whereNull('funding_applications.funding_request_id')
                                     ->whereNotNull('funding_requests.funding_sequence_id')
                                     ->whereColumn('funding_applications.funding_sequence_id', 'funding_requests.funding_sequence_id');
                              });
                      })
                      ->whereNull('deleted_at');
            });

        $activeApprovedRequestCount = $activeApprovedRequestsQuery->count();
        $activeApprovedRequests = $activeApprovedRequestsQuery->latest()->orderBy('id', 'desc')->get();

        if ($activeApprovedRequests->isEmpty()) {
            return [
                'active_approved_funding_requests' => [],
                'active_approved_funding_request' => null,
                'active_approved_funding_request_count' => 0,
            ];
        }

        $requests = $activeApprovedRequests->map(function ($req) {
            $estimatedRange = formatAmountRange($req->fundingSequence?->amount_range)
                ?? ($req->requested_amount ? currencyFormat($req->requested_amount, 0) : null);

            return [
                'id' => $req->id,
                'requested_amount' => $req->requested_amount,
                'estimated_funding_range' => $estimatedRange,
                'funding_type' => $req->funding_type,
            ];
        })->toArray();

        return [
            'active_approved_funding_requests' => $requests,
            // For backward compatibility / quick access to the latest one
            'active_approved_funding_request' => $requests[0],
            'active_approved_funding_request_count' => $activeApprovedRequestCount,
        ];
    }
}
