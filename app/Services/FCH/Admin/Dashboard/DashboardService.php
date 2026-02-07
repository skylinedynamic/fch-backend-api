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
        $data['is_host'] = $isHost;

        if ($isHost) {
            // --- HOST VIEW ---
            $data['counts'] = [
                'total_partners' => Partner::count(),
                'total_clients' => Borrower::count(),
                'total_leads' => Lead::count(),
                'total_commission' => Commission::sum('admin_commission'),
            ];

            $data['upcoming_meetings'] = Appointment::where('appointment_date', '>=', now())
                ->orderBy('appointment_date', 'asc')
                ->take(5)
                ->get();

            $data['latest_funding_applications'] = FundingApplication::with('borrower')
                ->latest()
                ->take(5)
                ->get();

            $data['latest_pre_qualifications'] = PreQualificationRequest::with('borrower')
                ->latest()
                ->take(5)
                ->get();

        } else {
            // --- PARTNER VIEW ---
            $data['counts'] = [
                'total_clients' => Borrower::where('partner_id', $partnerId)->count(),
                'total_leads' => Lead::where('partner_id', $partnerId)->count(),
                'my_commission' => Commission::where('partner_id', $partnerId)->sum('partner_commission'),
                'total_appointments' => Appointment::where('partner_id', $partnerId)->count(),
            ];

            $data['upcoming_meetings'] = Appointment::where('partner_id', $partnerId)
                ->where('appointment_date', '>=', now())
                ->orderBy('appointment_date', 'asc')
                ->take(5)
                ->get();

            $data['latest_funding_applications'] = FundingApplication::where('partner_id', $partnerId)
                ->with('borrower')
                ->latest()
                ->take(5)
                ->get();

            $data['latest_pre_qualifications'] = PreQualificationRequest::where('partner_id', $partnerId)
                ->with('borrower')
                ->latest()
                ->take(5)
                ->get();
        }

        return $data;
    }
}
