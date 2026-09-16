<?php

namespace App\Services\FCH\Admin\Dashboard;

use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Commission\Commission;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
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

        if ($isHost) {
            // --- HOST VIEW ---
            $data['counts'] = [
                'total_partners' => $partner->count(),
                'total_clients' => Borrower::count(),
                'total_leads' => Lead::count(),
                'total_commission' => Commission::where('payment_status', Commission::PAYMENT_STATUS_PAID)->sum('admin_commission_amount'),
            ];

        } else {
            // --- PARTNER VIEW ---
            $data['counts'] = [
                'total_clients' => $partner->borrowers->count(),
                'total_leads' => $partner->leads->count(),
                'total_commission' => $partner->commissions->where('payment_status', Commission::PAYMENT_STATUS_PAID)->sum('partner_commission_amount'),
                'funding_application' => FundingApplication::where('status', '!=', FundingApplication::ON_HOLD)->where('partner_id', $partner->id)->count(),
            ];
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

        $pendingRemindersQuery = \App\Models\Common\Reminder::where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
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
}
