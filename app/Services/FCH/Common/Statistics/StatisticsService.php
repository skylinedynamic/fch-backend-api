<?php

namespace App\Services\FCH\Common\Statistics;

use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    /**
     * Get Partner Profile Statistics
     *
     */
    public function getPartnerProfileStats()
    {
        $id = Auth::user()->id;
        $partnerId = request()->user()->partner_id;
        $stats = DB::table('partners as p')
            ->select(
                'p.id',
                DB::raw('(SELECT COUNT(*) FROM appointments WHERE scheduled_by = ' . $id . ') as total_meetings'),
                DB::raw('(SELECT COUNT(*) FROM borrowers WHERE partner_id = ' . $partnerId . ') as total_clients'),
                DB::raw('(SELECT COUNT(*) FROM invoices WHERE partner_id = ' . $partnerId . ') as total_invoice'),
                DB::raw('(SELECT COUNT(*) FROM support_tickets WHERE partner_id = ' . $partnerId . ') as total_tickets'),
                DB::raw('COALESCE((
                    SELECT enrollment_type 
                    FROM partner_subscriptions 
                    WHERE partner_id = ' . $partnerId . ' 
                    ORDER BY id DESC 
                    LIMIT 1
                ), "N/A") as enrollment_type')
            )
            ->where('p.id', $partnerId)
            ->first();

        return $stats;
    }
}
