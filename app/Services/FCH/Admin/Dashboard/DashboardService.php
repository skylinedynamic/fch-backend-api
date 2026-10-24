<?php

namespace App\Services\FCH\Admin\Dashboard;

use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Commission\Commission;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Commission\CommissionViewer;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\FCH\Common\Dashboard\DashboardAppointmentResource;
use App\Http\Resources\FCH\Common\Dashboard\DashboardFundingApplicationResource;
use App\Http\Resources\FCH\Common\Dashboard\DashboardLeadResource;
use App\Http\Resources\FCH\Common\Dashboard\DashboardPreQualificationResource;

class DashboardService
{
    /**
     * Get Dashboard Stats and Lists
     */
    public function getStats()
    {
        $user = Auth::user();

        $partner = Partner::findOrFail($user->partner_id);

        $isHost = (bool) $partner->is_host;
        $data = [];

        // The cards count what the Clients and Leads list cards count: deleted and
        // archived records left out, and visibleTo() limiting a non-host partner
        // to its own (the host sees every partner's).
        $totalClients = Borrower::visibleTo($user)->notArchived()->count();
        $totalLeads = Lead::visibleTo($user)->count();

        // The commission card, in the viewer's terms - the same figures as the
        // Commissions list cards (see CommissionViewer).
        $data['commission'] = $this->commissionCard(CommissionViewer::for($user));

        if ($isHost) {
            // --- HOST VIEW ---
            $data['counts'] = [
                'total_partners' => Partner::notArchived()->excludingHost()->count(),
                'total_clients' => $totalClients,
                'total_leads' => $totalLeads,
            ];

        } else {
            // --- PARTNER VIEW ---
            $data['counts'] = [
                'total_clients' => $totalClients,
                'total_leads' => $totalLeads,
                // On Hold included, as on the Funding Applications page card.
                'funding_application' => FundingApplication::where('partner_id', $partner->id)->count(),
            ];
        }


        // Kept for older screens: what this viewer has been paid. Partner staff
        // see no commission money.
        if (array_key_exists('paid_amount', $data['commission'])) {
            $data['counts']['total_commission'] = $data['commission']['paid_amount'];
        }

        $data['upcoming_meetings'] = DashboardAppointmentResource::collection(
            Appointment::where('appointment_date', '>=', now())
                ->where(function ($q) use ($user) {
                    $q->where('scheduled_by', $user->id)
                      ->orWhere('assigned_for', $user->id);
                })
                ->orderBy('appointment_date', 'asc')
                ->take(5)
                ->get()
        );

        $pendingRemindersQuery = \App\Models\Common\Reminder::createdByUser($user)
            ->where('status', \App\Models\Common\Reminder::STATUS_PENDING);

        $totalPendingReminders = (clone $pendingRemindersQuery)->count();

        $data['upcoming_tasks_reminders'] = \App\Http\Resources\FCH\Reminder\ReminderResource::collection(
            (clone $pendingRemindersQuery)
                ->withClient()
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get()
        )->resolve();

        $data['pending_reminders_count'] = $totalPendingReminders;

        $data['latest_funding_applications'] = DashboardFundingApplicationResource::collection(
            $partner->fundingApplications()->with('partner')    
                ->where('status', '!=', FundingApplication::ON_HOLD)
                ->latest()
                ->take(5)
                ->get()
        );

        $data['latest_pre_qualifications'] = DashboardPreQualificationResource::collection(
            $partner->preQualificationRequests()->with('partner')
                ->where('status', '!=', PreQualificationRequest::ON_HOLD)
                ->latest()
                ->take(5)
                ->get()
        );

        $data['latest_leads'] = DashboardLeadResource::collection(
            $partner->leads()
                ->latest()
                ->take(5)
                ->get()
        );


        return $data;
    }

    /** The dashboard's commission card for this viewer. */
    private function commissionCard(CommissionViewer $viewer): array
    {
        $summary = $viewer->summarize($viewer->scope(Commission::query()));

        // The headline is what was earned: commission on funded deals, paid out
        // or not. A deal not funded yet (Pending) has earned nothing.
        return match ($viewer->role) {
            CommissionViewer::HOST_STAFF => [
                'viewer' => $viewer->role,
                'earned_amount' => round($summary['my_paid_amount'] + $summary['my_expected_amount'], 2),
                'paid_amount' => $summary['my_paid_amount'],
                'expected_amount' => $summary['my_expected_amount'],
                'expected_deals' => $summary['my_expected_deals'],
            ],
            CommissionViewer::PARTNER_STAFF => [
                'viewer' => $viewer->role,
                'deals' => $summary['total_deals'],
                'completed_deals' => $summary['completed_deals'],
            ],
            default => ['earned_amount' => round($summary['paid_amount'] + $summary['unpaid_amount'], 2)]
                + array_intersect_key($summary, array_flip([
                    'viewer', 'paid_amount', 'pending_amount', 'unpaid_amount', 'paid_to_staff',
                ])),
        };
    }
}
