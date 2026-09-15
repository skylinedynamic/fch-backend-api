<?php

namespace App\Services\FCH\Admin\WeeklySummary;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Commission\Commission;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WeeklySummaryService
{
    /**
     * Build the full weekly executive summary data.
     *
     * @param  Carbon  $startDate
     * @param  Carbon  $endDate
     * @return array
     */
    public function getSummary(Carbon $startDate, Carbon $endDate): array
    {
        $prevStart = $startDate->copy()->subWeek();
        $prevEnd   = $endDate->copy()->subWeek();

        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end'   => $endDate->toDateString(),
            ],

            // ── Top KPI cards ──────────────────────────────────────────
            'gross_revenue'     => $this->buildGrossRevenue($startDate, $endDate, $prevStart, $prevEnd),
            'fourcorner_net'    => $this->buildFourCornerNet($startDate, $endDate, $prevStart, $prevEnd),
            'partner_payouts'   => $this->buildPartnerPayouts($startDate, $endDate, $prevStart, $prevEnd),
            'partners_added'    => $this->buildPartnersAdded($startDate, $endDate, $prevStart, $prevEnd),

            // ── Second row KPI cards ───────────────────────────────────
            'clients_added'         => $this->buildClientsAdded($startDate, $endDate, $prevStart, $prevEnd),
            'leads_captured'        => $this->buildLeadsCaptured($startDate, $endDate, $prevStart, $prevEnd),
            'prequal_applications'  => $this->buildPreQualApplications($startDate, $endDate, $prevStart, $prevEnd),
            'funding_applications'  => $this->buildFundingApplications($startDate, $endDate, $prevStart, $prevEnd),

            // ── FourCorner host summary block ──────────────────────────
            'host_summary' => $this->buildHostSummary($startDate, $endDate),

            // ── New Partners table ─────────────────────────────────────
            'new_partners' => $this->buildNewPartners($startDate, $endDate),

            // ── Subscription summary ───────────────────────────────────
            'subscription_summary' => $this->buildSubscriptionSummary($startDate, $endDate),

            // ── Funding commission details (accordion) ─────────────────
            'commission_details' => $this->buildCommissionDetails($startDate, $endDate),
        ];
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────────────────

    private function pctChange(float $current, float $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : null;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function direction(float $current, float $previous): string
    {
        return $current >= $previous ? 'up' : 'down';
    }

    // ── Gross Revenue (admin_commission_amount = host's cut; total gross = sum of total_commission_amount)
    private function buildGrossRevenue(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $current  = (float) Commission::whereBetween('created_at', [$s, $e])->sum('total_commission_amount');
        $previous = (float) Commission::whereBetween('created_at', [$ps, $pe])->sum('total_commission_amount');
        $pct      = $this->pctChange($current, $previous);

        return [
            'amount'         => $current,
            'vs_last_week'   => $pct,
            'direction'      => $this->direction($current, $previous),
        ];
    }

    // ── FourCorner Net Share = admin_commission_amount
    private function buildFourCornerNet(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $current  = (float) Commission::whereBetween('created_at', [$s, $e])->sum('admin_commission_amount');
        $previous = (float) Commission::whereBetween('created_at', [$ps, $pe])->sum('admin_commission_amount');
        $pct      = $this->pctChange($current, $previous);

        return [
            'amount'       => $current,
            'vs_last_week' => $pct,
            'direction'    => $this->direction($current, $previous),
        ];
    }

    // ── Partner Payouts (partner_commission_amount)
    private function buildPartnerPayouts(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $base = fn($start, $end) => Commission::whereBetween('created_at', [$start, $end]);

        $current  = (float) $base($s, $e)->sum('partner_commission_amount');
        $previous = (float) $base($ps, $pe)->sum('partner_commission_amount');
        $paid     = (float) $base($s, $e)->where('payment_status', Commission::PAYMENT_STATUS_PAID)->sum('partner_commission_amount');
        $pending  = (float) $base($s, $e)->where('payment_status', Commission::PAYMENT_STATUS_PENDING)->sum('partner_commission_amount');
        $pct      = $this->pctChange($current, $previous);

        return [
            'amount'       => $current,
            'paid'         => $paid,
            'pending'      => $pending,
            'vs_last_week' => $pct,
            'direction'    => $this->direction($current, $previous),
        ];
    }

    // ── Partners Added
    private function buildPartnersAdded(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $current  = Partner::whereNotNull('is_host')->where('is_host', false)
                        ->whereBetween('created_at', [$s, $e])->count();
        $previous = Partner::whereNotNull('is_host')->where('is_host', false)
                        ->whereBetween('created_at', [$ps, $pe])->count();
        $pct = $this->pctChange($current, $previous);

        return [
            'count'        => $current,
            'vs_last_week' => $pct,
            'direction'    => $this->direction($current, $previous),
        ];
    }

    // ── Clients Added (borrowers) with pre-qual breakdown
    private function buildClientsAdded(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $current  = Borrower::whereBetween('created_at', [$s, $e])->count();
        $previous = Borrower::whereBetween('created_at', [$ps, $pe])->count();
        $pct      = $this->pctChange($current, $previous);

        // Pre-qual status breakdown based on each borrower's latest pre-qual status
        $preQualStatuses = PreQualificationRequest::whereBetween('pre_qualification_requests.created_at', [$s, $e])
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        // Borrowers with NO pre-qual at all in the window
        $borrowerIds = Borrower::whereBetween('created_at', [$s, $e])->pluck('id');
        $borrowersWithPreQual = PreQualificationRequest::whereBetween('pre_qualification_requests.created_at', [$s, $e])
            ->whereIn('borrower_id', $borrowerIds)
            ->distinct('borrower_id')
            ->count('borrower_id');
        $noPreQual = max(0, $current - $borrowersWithPreQual);

        // Funded count (borrowers with at least one funded application)
        $fundedCount = FundingApplication::where('status', FundingApplication::FUNDED)
            ->whereBetween('funding_applications.created_at', [$s, $e])
            ->whereIn('borrower_id', $borrowerIds)
            ->distinct('borrower_id')
            ->count('borrower_id');

        // Funding Applied count
        $fundingApplied = FundingApplication::whereBetween('funding_applications.created_at', [$s, $e])
            ->whereIn('borrower_id', $borrowerIds)
            ->distinct('borrower_id')
            ->count('borrower_id');

        return [
            'count'                => $current,
            'vs_last_week'         => $pct,
            'direction'            => $this->direction($current, $previous),
            'no_prequal'           => $noPreQual,
            'prequal_pending'      => $preQualStatuses[PreQualificationRequest::PENDING] ?? 0,
            'prequal_approved'     => $preQualStatuses[PreQualificationRequest::APPROVED] ?? 0,
            'prequal_declined'     => $preQualStatuses[PreQualificationRequest::DECLINED] ?? 0,
            'funding_applied'      => $fundingApplied,
            'funded'               => $fundedCount,
        ];
    }

    // ── Leads Captured
    private function buildLeadsCaptured(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $current  = Lead::whereBetween('created_at', [$s, $e])->count();
        $previous = Lead::whereBetween('created_at', [$ps, $pe])->count();
        $pct      = $this->pctChange($current, $previous);

        $new       = Lead::whereBetween('created_at', [$s, $e])->where('status', Lead::STATUS_NEW_LEAD)->count();
        $contacted = Lead::whereBetween('created_at', [$s, $e])->whereIn('status', [Lead::STATUS_CONTACT_INITIATED, Lead::STATUS_LEAD_IDENTIFIED, Lead::STATUS_MEETING_ARRANGED, Lead::STATUS_FOLLOW_UP, Lead::STATUS_NEGOTIATION, Lead::STATUS_PROCRASTINATION])->count();
        $converted = Lead::whereBetween('created_at', [$s, $e])->whereIn('status', [Lead::STATUS_LEAD_COMPLETED, Lead::STATUS_APPLICATION_ALLOWED])->count();

        return [
            'count'        => $current,
            'vs_last_week' => $pct,
            'direction'    => $this->direction($current, $previous),
            'new'          => $new,
            'contacted'    => $contacted,
            'converted'    => $converted,
        ];
    }

    // ── Pre-Qual Applications
    private function buildPreQualApplications(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $base = fn($start, $end) => PreQualificationRequest::whereBetween('created_at', [$start, $end]);

        $current  = $base($s, $e)->count();
        $previous = $base($ps, $pe)->count();
        $pct      = $this->pctChange($current, $previous);

        return [
            'count'        => $current,
            'vs_last_week' => $pct,
            'direction'    => $this->direction($current, $previous),
            'approved'     => $base($s, $e)->where('status', PreQualificationRequest::APPROVED)->count(),
            'declined'     => $base($s, $e)->where('status', PreQualificationRequest::DECLINED)->count(),
            'pending'      => $base($s, $e)->where('status', PreQualificationRequest::PENDING)->count(),
        ];
    }

    // ── Funding Applications
    private function buildFundingApplications(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $base = fn($start, $end) => FundingApplication::whereBetween('created_at', [$start, $end])
                                        ->where('status', '!=', FundingApplication::ON_HOLD);

        $current  = $base($s, $e)->count();
        $previous = $base($ps, $pe)->count();
        $pct      = $this->pctChange($current, $previous);

        $approvedStatuses = [
            FundingApplication::APPROVED_CONDITIONAL,
            FundingApplication::APPROVED_FINAL,
            FundingApplication::CLEARED_TO_FUND,
            FundingApplication::FUNDED,
            FundingApplication::CLOSED_COMPLETED,
        ];

        $declinedStatuses = [
            FundingApplication::DENIED,
        ];

        $underwritingStatuses = [
            FundingApplication::UNDERWRITING_REVIEW,
            FundingApplication::IN_VERIFICATION,
            FundingApplication::MISSING_DOCUMENTS,
        ];

        return [
            'count'        => $current,
            'vs_last_week' => $pct,
            'direction'    => $this->direction($current, $previous),
            'approved'     => FundingApplication::whereBetween('created_at', [$s, $e])
                                ->whereIn('status', $approvedStatuses)->count(),
            'declined'     => FundingApplication::whereBetween('created_at', [$s, $e])
                                ->whereIn('status', $declinedStatuses)->count(),
            'underwriting' => FundingApplication::whereBetween('created_at', [$s, $e])
                                ->whereIn('status', $underwritingStatuses)->count(),
        ];
    }

    // ── Host (FourCorner Funding direct) Summary
    private function buildHostSummary(Carbon $s, Carbon $e): array
    {
        $host = Partner::host();

        if (!$host) {
            return [];
        }

        $directClients  = Borrower::where('partner_id', $host->id)->whereBetween('created_at', [$s, $e])->count();
        $leadsSubmitted = Lead::where('partner_id', $host->id)->whereBetween('created_at', [$s, $e])->count();
        $preQualsFiled  = PreQualificationRequest::where('partner_id', $host->id)->whereBetween('created_at', [$s, $e])->count();
        $fundingApps    = FundingApplication::where('partner_id', $host->id)
                            ->where('status', '!=', FundingApplication::ON_HOLD)
                            ->whereBetween('created_at', [$s, $e])->count();

        $txVolume       = (float) FundingApplication::where('partner_id', $host->id)
                            ->whereBetween('created_at', [$s, $e])
                            ->whereNotNull('total_funded_amount')
                            ->sum('total_funded_amount');

        $hostRevenue    = (float) Commission::where('partner_id', $host->id)
                            ->whereBetween('created_at', [$s, $e])
                            ->sum('admin_commission_amount');

        $commPaid    = (float) Commission::where('partner_id', $host->id)
                            ->whereBetween('created_at', [$s, $e])
                            ->where('payment_status', Commission::PAYMENT_STATUS_PAID)
                            ->sum('partner_commission_amount');

        $commPending = (float) Commission::where('partner_id', $host->id)
                            ->whereBetween('created_at', [$s, $e])
                            ->where('payment_status', Commission::PAYMENT_STATUS_PENDING)
                            ->sum('partner_commission_amount');

        return [
            'partner_id'      => $host->id,
            'name'            => $host->company_name ?? $host->full_name,
            'direct_clients'  => $directClients,
            'leads_submitted' => $leadsSubmitted,
            'prequals_filed'  => $preQualsFiled,
            'funding_apps'    => $fundingApps,
            'tx_volume'       => $txVolume,
            'host_revenue'    => $hostRevenue,
            'commission_paid'    => $commPaid,
            'commission_pending' => $commPending,
        ];
    }

    // ── New Partners table (non-host partners created this week with their stats)
    private function buildNewPartners(Carbon $s, Carbon $e): array
    {
        $partners = Partner::where('is_host', false)
            ->whereBetween('created_at', [$s, $e])
            ->get();

        $rows   = [];
        $totals = [
            'clients'    => 0,
            'leads'      => 0,
            'pre_quals'  => 0,
            'tx_volume'  => 0.0,
            'commission' => 0.0,
        ];

        foreach ($partners as $partner) {
            $clients   = Borrower::where('partner_id', $partner->id)->whereBetween('created_at', [$s, $e])->count();
            $leads     = Lead::where('partner_id', $partner->id)->whereBetween('created_at', [$s, $e])->count();
            $preQuals  = PreQualificationRequest::where('partner_id', $partner->id)->whereBetween('created_at', [$s, $e])->count();
            $txVolume  = (float) FundingApplication::where('partner_id', $partner->id)
                            ->whereBetween('created_at', [$s, $e])
                            ->whereNotNull('total_funded_amount')
                            ->sum('total_funded_amount');

            $commTotal   = (float) Commission::where('partner_id', $partner->id)->whereBetween('created_at', [$s, $e])->sum('partner_commission_amount');
            $commPaid    = (float) Commission::where('partner_id', $partner->id)->whereBetween('created_at', [$s, $e])->where('payment_status', Commission::PAYMENT_STATUS_PAID)->sum('partner_commission_amount');
            $commPending = (float) Commission::where('partner_id', $partner->id)->whereBetween('created_at', [$s, $e])->where('payment_status', Commission::PAYMENT_STATUS_PENDING)->sum('partner_commission_amount');

            $payoutStatus = 'Pending';
            if ($commTotal > 0 && $commPending == 0) {
                $payoutStatus = 'Paid';
            } elseif ($commTotal > 0 && $commPaid > 0) {
                $payoutStatus = 'Partial';
            }

            $rows[] = [
                'id'            => $partner->id,
                'name'          => $partner->company_name ?? $partner->full_name,
                'clients'       => $clients,
                'leads'         => $leads,
                'pre_quals'     => $preQuals,
                'tx_volume'     => $txVolume,
                'commission'    => $commTotal,
                'comm_paid'     => $commPaid,
                'comm_pending'  => $commPending,
                'payout_status' => $payoutStatus,
            ];

            $totals['clients']    += $clients;
            $totals['leads']      += $leads;
            $totals['pre_quals']  += $preQuals;
            $totals['tx_volume']  += $txVolume;
            $totals['commission'] += $commTotal;
        }

        return [
            'rows'   => $rows,
            'totals' => $totals,
        ];
    }

    // ── Subscription Summary
    private function buildSubscriptionSummary(Carbon $s, Carbon $e): array
    {
        $activeAccounts  = Subscription::where('status', Subscription::STATUS_ACTIVE)->count();
        $newSignups      = Subscription::whereBetween('created_at', [$s, $e])->count();
        $cancellations   = Subscription::whereBetween('updated_at', [$s, $e])
                            ->where('status', Subscription::STATUS_EXPIRED)->count();

        // Subscription revenue = monthly platform fees billed this period (via partner subscriptions active)
        $subscriptionRevenue = (float) Subscription::where('status', Subscription::STATUS_ACTIVE)
                                ->sum('monthly_platform_fee');

        // By tier (using partner_type / enrollment_type as proxy for plan tier)
        $byTier = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->select('partner_type', DB::raw('count(*) as count'), DB::raw('sum(monthly_platform_fee) as monthly_revenue'))
            ->groupBy('partner_type')
            ->get()
            ->map(fn($row) => [
                'tier'            => $row->partner_type ?? 'Other',
                'accounts'        => (int) $row->count,
                'monthly_revenue' => (float) $row->monthly_revenue,
            ])
            ->values()
            ->toArray();

        return [
            'active_accounts'      => $activeAccounts,
            'new_signups'          => $newSignups,
            'cancellations'        => $cancellations,
            'subscription_revenue' => $subscriptionRevenue,
            'by_tier'              => $byTier,
        ];
    }

    // ── Commission Details (accordion per partner)
    private function buildCommissionDetails(Carbon $s, Carbon $e): array
    {
        $totalPaid    = (float) Commission::whereBetween('created_at', [$s, $e])->where('payment_status', Commission::PAYMENT_STATUS_PAID)->sum('partner_commission_amount');
        $totalPending = (float) Commission::whereBetween('created_at', [$s, $e])->where('payment_status', Commission::PAYMENT_STATUS_PENDING)->sum('partner_commission_amount');
        $totalAll     = (float) Commission::whereBetween('created_at', [$s, $e])->sum('partner_commission_amount');

        // Group commissions by partner
        $commissions = Commission::whereBetween('created_at', [$s, $e])
            ->with(['partner:id,full_name,company_name', 'borrower:id,first_name,last_name', 'fundingApplication:id,total_funded_amount'])
            ->get();

        $grouped = $commissions->groupBy('partner_id');

        $partners = [];
        foreach ($grouped as $partnerId => $records) {
            $partner = $records->first()->partner;

            $paidAmt    = (float) $records->where('payment_status', Commission::PAYMENT_STATUS_PAID)->sum('partner_commission_amount');
            $pendingAmt = (float) $records->where('payment_status', Commission::PAYMENT_STATUS_PENDING)->sum('partner_commission_amount');
            $totalAmt   = (float) $records->sum('partner_commission_amount');
            $avgRate    = round((float) ($records->avg('partner_commission') ?? 0), 2);

            $rows = $records->map(fn($c) => [
                'client_name'       => $c->borrower
                                        ? personName($c->borrower->first_name ?? null, $c->borrower->middle_name ?? null, $c->borrower->last_name ?? null)
                                        : '—',
                'commission_amount' => (float) $c->partner_commission_amount,
                'commission_rate'   => (float) $c->partner_commission,
                'status'            => $c->payment_status,
                'paid_date'         => $c->payout_date?->toDateString(),
            ])->values()->toArray();

            $partners[] = [
                'partner_id'   => $partnerId,
                'partner_name' => $partner ? ($partner->company_name ?? $partner->full_name) : 'Unknown',
                'record_count' => $records->count(),
                'avg_rate'     => $avgRate,
                'paid'         => $paidAmt,
                'pending'      => $pendingAmt,
                'total'        => $totalAmt,
                'rows'         => $rows,
            ];
        }

        return [
            'total_paid'    => $totalPaid,
            'total_pending' => $totalPending,
            'total'         => $totalAll,
            'partners'      => $partners,
        ];
    }
}
