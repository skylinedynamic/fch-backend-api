<?php

namespace App\Services\FCH\Common\OwnerSummary;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Commission\Commission;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Invoice\Invoice;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Commission\CommissionViewer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The host owner's business summary, built only from what the database records
 * today. What it deliberately does not show, because nothing records it:
 * churn (a cancelled subscription keeps no cancellation date) and lead-to-
 * funded conversion (a client keeps no link to the lead it came from).
 *
 * Definitions, so every number means one thing:
 *   cash collected  succeeded payments in the environment asked for, on an
 *                   invoice the host is paid on (InvoicePartyResolver's rule),
 *                   commission payouts excluded - that money goes out
 *   owed            the same invoices, Unpaid/Overdue or Failed
 *   funded          Closed (Completed) with a funded date in the period
 *   commission      booked: what the commission records say, not cash in
 *   stalled         open, with no status change for STALLED_AFTER_DAYS
 */
class OwnerSummaryService
{
    public const ENVIRONMENT_PRODUCTION = 'production';
    public const ENVIRONMENT_SANDBOX = 'sandbox';
    public const ENVIRONMENTS = [
        self::ENVIRONMENT_PRODUCTION => PaymentProvider::ENVIRONMENT_PRODUCTION,
        self::ENVIRONMENT_SANDBOX => PaymentProvider::ENVIRONMENT_SANDBOX,
    ];

    public const STREAM_PARTNER_ENROLLMENT = 'partner_enrollment';
    public const STREAM_PLATFORM_FEE = 'platform_fee';
    public const STREAM_USER_SEAT = 'user_seat';
    public const STREAM_BORROWER_FEE = 'borrower_fee';
    public const STREAM_CLIENT_ENROLLMENT = 'client_enrollment';
    public const STREAMS = [
        self::STREAM_PARTNER_ENROLLMENT,
        self::STREAM_PLATFORM_FEE,
        self::STREAM_USER_SEAT,
        self::STREAM_BORROWER_FEE,
        self::STREAM_CLIENT_ENROLLMENT,
    ];

    /** The open application statuses, grouped into the stages the owner reads. */
    public const STAGE_GROUPS = [
        'intake' => [
            FundingApplication::PENDING,
            FundingApplication::SUBMITTED,
            FundingApplication::MISSING_DOCUMENTS,
            FundingApplication::PENDING_CLARIFICATION,
        ],
        'underwriting' => [
            FundingApplication::UNDERWRITING_REVIEW,
            FundingApplication::IN_VERIFICATION,
            FundingApplication::READY_FOR_SUBMISSION,
        ],
        'approved' => [
            FundingApplication::APPROVED_CONDITIONAL,
            FundingApplication::APPROVED_FINAL,
        ],
        'with_lender' => [
            FundingApplication::MATCHED_TO_LENDER,
            FundingApplication::SUBMITTED_TO_LENDER,
            FundingApplication::LENDER_REVIEWING,
            FundingApplication::OFFER_ISSUED,
            FundingApplication::OFFER_ACCEPTED,
        ],
        'closing' => [
            FundingApplication::CONTRACTING_DOCS_OUT,
            FundingApplication::CLEARED_TO_FUND,
            FundingApplication::FUNDED,
        ],
        'on_hold' => [
            FundingApplication::ON_HOLD,
        ],
    ];

    public const ACTIVITY_FUNDED = 'funded';
    public const LEAD_SOURCE_NOT_SET = 'not_set';

    public const STALLED_AFTER_DAYS = 14;
    public const STALLED_LIST_LIMIT = 10;
    public const UNPAID_AGE_DAYS = 30;
    public const ACTIVE_PARTNER_DAYS = 30;
    public const TRIAL_ENDING_DAYS = 14;
    public const TOP_PARTNERS_LIMIT = 5;
    public const CHART_MONTHS = 6;

    private const OWED_STATUSES = [Invoice::STATUS_UNPAID, Invoice::STATUS_OVERDUE];

    private Partner $host;
    private OwnerSummaryPeriod $period;
    private int $environment;
    private ?Collection $open = null;

    public function summary(User $owner, OwnerSummaryPeriod $period, string $environment): array
    {
        $this->host = $owner->partner;
        $this->period = $period;
        $this->environment = self::ENVIRONMENTS[$environment];
        $this->open = null;

        $funded = $this->fundedIn($period->from, $period->to);
        $receivables = $this->receivables();
        $commissions = $this->commissions($owner);
        $stalled = $this->stalledDeals();

        return [
            'period' => $period->toArray(),
            'environment' => $environment,
            'kpis' => [
                'cash_collected' => [
                    'current' => $this->cashTotal(...$period->between()),
                    'previous' => $this->cashTotal(...$period->previousBetween()),
                ],
                'outstanding' => [
                    'amount' => round($receivables['unpaid_recent']['amount'] + $receivables['unpaid_over_30_days']['amount'] + $receivables['failed']['amount'], 2),
                    'invoices' => $receivables['unpaid_recent']['invoices'] + $receivables['unpaid_over_30_days']['invoices'] + $receivables['failed']['invoices'],
                ],
                'funded' => [
                    'amount' => $this->money($funded->sum('total_funded_amount')),
                    'deals' => $funded->count(),
                    'previous_amount' => $this->money($this->fundedIn($period->previousFrom, $period->previousTo)->sum('total_funded_amount')),
                ],
                'new_clients' => [
                    'current' => Borrower::whereBetween('created_at', $period->between())->count(),
                    'previous' => Borrower::whereBetween('created_at', $period->previousBetween())->count(),
                ],
                'new_leads' => [
                    'current' => Lead::whereBetween('created_at', $period->between())->count(),
                    'previous' => Lead::whereBetween('created_at', $period->previousBetween())->count(),
                ],
                'active_partners' => [
                    'current' => $this->activePartners(),
                    'total' => Partner::excludingHost()->count(),
                ],
            ],
            'cash_by_stream' => $this->cashByStream(),
            'receivables' => $receivables,
            'commissions' => $commissions['period'],
            'attention' => $this->attention($receivables, $commissions['payouts_due'], $stalled),
            'pipeline' => [
                'open' => $this->openTotals(),
                'groups' => $this->openByGroup(),
                'activity' => $this->stageActivity() + [self::ACTIVITY_FUNDED => $funded->count()],
                'median_days_to_fund' => $this->medianDaysToFund($funded),
            ],
            'stalled_deals' => array_slice($stalled, 0, self::STALLED_LIST_LIMIT),
            'lead_sources' => $this->leadSources(),
            'partners' => $this->partners(),
            'top_partners' => $this->topPartners($funded),
            'monthly' => $this->monthly(),
        ];
    }

    // ------------------------------------------------------------------ money

    /**
     * Invoices the host is paid on: a borrower fee (a partner's debt to the
     * host), anything billed to a partner, or a client of the host's own -
     * never a commission payout, which is money going out.
     */
    private function hostInvoices(Builder $query): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('invoices.type')->orWhere('invoices.type', '!=', Invoice::INVOICE_TYPE_COMMISSION_PAYOUT))
            ->where(fn ($q) => $q->where('invoices.type', Invoice::INVOICE_TYPE_BORROWER_FEE)
                ->orWhere('invoices.entity_type', Partner::class)
                ->orWhere('invoices.partner_id', $this->host->id)
                ->orWhereNull('invoices.partner_id'));
    }

    /** Payments on host invoices, in the environment asked for. */
    private function hostPayments(): Builder
    {
        $query = Payment::query()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereNull('invoices.deleted_at')
            ->where('payments.is_environment', $this->environment);

        return $this->hostInvoices($query);
    }

    private function cashTotal(CarbonImmutable $start, CarbonImmutable $end): float
    {
        return $this->money($this->hostPayments()
            ->where('payments.status', Payment::STATUS_SUCCEEDED)
            ->where('payments.transaction_date', '>=', $start)
            ->where('payments.transaction_date', '<', $end)
            ->sum('payments.amount'));
    }

    private function cashByStream(): array
    {
        [$start, $end] = $this->period->between();

        $rows = $this->hostPayments()
            ->where('payments.status', Payment::STATUS_SUCCEEDED)
            ->where('payments.transaction_date', '>=', $start)
            ->where('payments.transaction_date', '<', $end)
            ->get(['payments.amount', 'invoices.type as invoice_type', 'invoices.entity_type as invoice_entity_type']);

        $byStream = $rows->groupBy(fn ($row) => $this->stream($row->invoice_type, $row->invoice_entity_type));

        return array_map(fn (string $stream) => [
            'stream' => $stream,
            'amount' => $this->money(($byStream[$stream] ?? collect())->sum('amount')),
            'payments' => ($byStream[$stream] ?? collect())->count(),
        ], self::STREAMS);
    }

    private function stream(?string $type, ?string $entityType): string
    {
        return match (true) {
            $type === Invoice::INVOICE_TYPE_PLATFORM_FEE => self::STREAM_PLATFORM_FEE,
            $type === Invoice::INVOICE_TYPE_USER_SEAT => self::STREAM_USER_SEAT,
            $type === Invoice::INVOICE_TYPE_BORROWER_FEE => self::STREAM_BORROWER_FEE,
            $entityType === Partner::class => self::STREAM_PARTNER_ENROLLMENT,
            default => self::STREAM_CLIENT_ENROLLMENT,
        };
    }

    private function receivables(): array
    {
        $agedBefore = $this->period->today->subDays(self::UNPAID_AGE_DAYS)->utc();
        $owed = fn () => $this->hostInvoices(Invoice::query())->whereIn('invoices.status', self::OWED_STATUSES);

        return [
            'unpaid_recent' => $this->invoiceTotals($owed()->where('invoices.created_at', '>=', $agedBefore)),
            'unpaid_over_30_days' => $this->invoiceTotals($owed()->where('invoices.created_at', '<', $agedBefore)),
            'failed' => $this->invoiceTotals($this->hostInvoices(Invoice::query())->where('invoices.status', Invoice::STATUS_FAILED)),
        ];
    }

    private function invoiceTotals(Builder $query): array
    {
        return [
            'amount' => $this->money((clone $query)->sum('invoices.payment_amount')),
            'invoices' => (clone $query)->count(),
        ];
    }

    /**
     * The host's share booked on deals funded in the period, and every payout
     * still due (whenever the deal was funded) - one CommissionViewer, so the
     * numbers match the Commissions screen.
     */
    private function commissions(User $owner): array
    {
        $viewer = CommissionViewer::for($owner);
        $inPeriod = Commission::query()->whereHas('fundingApplication', fn ($q) => $this->fundedQuery($q->withTrashed(), $this->period->from, $this->period->to));

        $summary = $viewer->summarize(clone $inPeriod);
        [$dueAmount, $dueDeals] = $viewer->payoutsDue(Commission::query());

        return [
            'period' => [
                'host_share_booked' => $summary['total_commission_amount'],
                'partner_share' => $summary['partner_commission_amount'],
                'paid_to_partners' => $this->money((clone $inPeriod)->where('payment_status', Commission::PAYMENT_STATUS_PAID)->sum('partner_commission_amount')),
                'paid_to_staff' => $summary['paid_to_staff'],
                'payouts_due' => $summary['payouts_due_amount'],
            ],
            'payouts_due' => ['amount' => $dueAmount, 'deals' => $dueDeals],
        ];
    }

    // -------------------------------------------------------------- attention

    private function attention(array $receivables, array $payoutsDue, array $stalled): array
    {
        [$start, $end] = $this->period->between();
        $paymentsInPeriod = fn (string $status) => $this->hostPayments()
            ->where('payments.status', $status)
            ->where('payments.transaction_date', '>=', $start)
            ->where('payments.transaction_date', '<', $end)
            ->count();

        $today = $this->period->today;
        $trialEnding = fn (Builder $q) => $q->where('is_free_trail', true)
            ->whereDate('free_trail_end_date', '>=', $today->toDateString())
            ->whereDate('free_trail_end_date', '<=', $today->addDays(self::TRIAL_ENDING_DAYS)->toDateString());

        return [
            'unpaid_over_30_days' => $receivables['unpaid_over_30_days'],
            'failed_invoices' => $receivables['failed'],
            'partners_owing' => $this->hostInvoices(Invoice::query())
                ->where('invoices.entity_type', Partner::class)
                ->whereIn('invoices.status', [...self::OWED_STATUSES, Invoice::STATUS_FAILED])
                ->distinct()
                ->count('invoices.entity_id'),
            'suspended_partners' => Partner::excludingHost()->where('status', Partner::STATUS_SUSPENDED)->count(),
            'failed_payments' => $paymentsInPeriod(Payment::STATUS_FAILED),
            'disputed_payments' => $paymentsInPeriod(Payment::STATUS_DISPUTED),
            // Waiting on someone whatever the environment.
            'manual_payments_to_approve' => $this->hostInvoices(
                Payment::query()->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->whereNull('invoices.deleted_at')
            )->where('payments.status', Payment::STATUS_PROCESSING)->count(),
            'payouts_due' => $payoutsDue,
            'unreviewed_embedded_applications' => EmbedFormSubmission::where('partner_id', $this->host->id)
                ->where('status', EmbedFormSubmission::STATUS_NEW)
                ->count(),
            'pending_prequalifications' => PreQualificationRequest::where('status', PreQualificationRequest::PENDING)->count(),
            'trials_ending' => [
                'partners' => $trialEnding(Partner::excludingHost())->count(),
                'clients' => $trialEnding(Borrower::where('partner_id', $this->host->id))->count(),
            ],
            'stalled_deals' => count($stalled),
            // Of all of them; the list below stops at STALLED_LIST_LIMIT.
            'stalled_requested' => $this->money(array_sum(array_column($stalled, 'requested_amount'))),
        ];
    }

    // --------------------------------------------------------------- pipeline

    private function fundedQuery(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->where('status', FundingApplication::CLOSED_COMPLETED)
            ->whereDate('funded_date', '>=', $from->toDateString())
            ->whereDate('funded_date', '<=', $to->toDateString());
    }

    private function fundedIn(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->fundedQuery(FundingApplication::query(), $from, $to)
            ->get(['id', 'partner_id', 'total_funded_amount', 'funded_date', 'created_at']);
    }

    private function medianDaysToFund(Collection $funded): ?float
    {
        $days = $funded
            ->map(fn (FundingApplication $application) => (int) $application->created_at->copy()->tz($this->period->timezone)->startOfDay()
                ->diffInDays(CarbonImmutable::parse($application->funded_date->toDateString(), $this->period->timezone)))
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return null;
        }

        $middle = intdiv($days->count(), 2);

        return $days->count() % 2
            ? (float) $days[$middle]
            : ($days[$middle - 1] + $days[$middle]) / 2;
    }

    /** @return list<string> */
    private function openStatuses(): array
    {
        return array_merge(...array_values(self::STAGE_GROUPS));
    }

    /** Open applications with what each asks for (its own amount, or its funding request's). Loaded once per summary. */
    private function openApplications(): Collection
    {
        return $this->open ??= FundingApplication::with(['fundingRequest', 'borrower' => fn ($q) => $q->withTrashed(), 'partner'])
            ->whereIn('status', $this->openStatuses())
            ->get()
            ->map(fn (FundingApplication $application) => [
                'application' => $application,
                'requested' => (float) ($application->requestedAmountOrRequest() ?? 0),
            ]);
    }

    private function openTotals(): array
    {
        $open = $this->openApplications();

        return ['count' => $open->count(), 'requested' => $this->money($open->sum('requested'))];
    }

    private function openByGroup(): array
    {
        $open = $this->openApplications();

        return array_map(function (string $key) use ($open) {
            $rows = $open->filter(fn ($row) => in_array($row['application']->status, self::STAGE_GROUPS[$key], true));

            return [
                'key' => $key,
                'statuses' => self::STAGE_GROUPS[$key],
                'count' => $rows->count(),
                'requested' => $this->money($rows->sum('requested')),
            ];
        }, array_keys(self::STAGE_GROUPS));
    }

    /**
     * How many applications reached each open stage during the period, from
     * the audit trail (opened in a status, or moved to it). Counts per stage,
     * not a conversion rate: an application counts once in each stage it
     * reached. Funded is not here: its funded date is the record of that, and
     * it goes back further than the trail does.
     */
    private function stageActivity(): array
    {
        [$start, $end] = $this->period->between();

        $events = FundingApplicationAuditEvent::query()
            ->whereIn('event', [FundingApplicationAuditEvent::EVENT_CREATED, FundingApplicationAuditEvent::EVENT_STATUS_CHANGED])
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->get(['funding_application_id', 'event', 'changes', 'context']);

        $reached = array_fill_keys(array_keys(self::STAGE_GROUPS), []);

        foreach ($events as $event) {
            $stage = $this->stageOf($this->statusOf($event));
            if ($stage !== null) {
                $reached[$stage][$event->funding_application_id] = true;
            }
        }

        return array_map('count', $reached);
    }

    private function statusOf(FundingApplicationAuditEvent $event): ?string
    {
        if ($event->event === FundingApplicationAuditEvent::EVENT_CREATED) {
            return $event->context['status'] ?? null;
        }

        foreach ($event->changes ?? [] as $change) {
            if (($change['field'] ?? null) === 'status') {
                return $change['new'] ?? null;
            }
        }

        return null;
    }

    private function stageOf(?string $status): ?string
    {
        foreach (self::STAGE_GROUPS as $key => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Open applications whose status has not changed for STALLED_AFTER_DAYS,
     * longest-waiting first. The last change comes from the audit trail; an
     * application with none falls back to when it was opened.
     */
    private function stalledDeals(): array
    {
        $open = $this->openApplications()->keyBy(fn ($row) => $row['application']->id);
        if ($open->isEmpty()) {
            return [];
        }

        $lastMoves = FundingApplicationAuditEvent::query()
            ->whereIn('funding_application_id', $open->keys())
            ->whereIn('event', [FundingApplicationAuditEvent::EVENT_CREATED, FundingApplicationAuditEvent::EVENT_STATUS_CHANGED])
            ->groupBy('funding_application_id')
            ->selectRaw('funding_application_id, MAX(occurred_at) as last_move')
            ->pluck('last_move', 'funding_application_id');

        $today = $this->period->today;

        return $open
            ->map(function ($row, $id) use ($lastMoves, $today) {
                $application = $row['application'];
                $lastMove = isset($lastMoves[$id])
                    ? CarbonImmutable::parse($lastMoves[$id], 'UTC')
                    : CarbonImmutable::parse($application->created_at);
                $days = (int) $lastMove->tz($this->period->timezone)->startOfDay()->diffInDays($today);

                return [
                    'id' => $application->id,
                    'application_number' => $application->application_number,
                    'client' => $application->borrower?->business_name ?: $application->full_name,
                    'partner' => $application->partner?->company_name,
                    'status' => $application->status,
                    'requested_amount' => $this->money($row['requested']),
                    'days_in_status' => $days,
                ];
            })
            ->filter(fn (array $deal) => $deal['days_in_status'] >= self::STALLED_AFTER_DAYS)
            ->sortByDesc('days_in_status')
            ->values()
            ->all();
    }

    // ----------------------------------------------------------------- growth

    private function activePartners(): int
    {
        $since = $this->period->today->subDays(self::ACTIVE_PARTNER_DAYS)->utc();

        return Partner::excludingHost()
            ->whereIn('id', Lead::where('created_at', '>=', $since)->whereNotNull('partner_id')->select('partner_id'))
            ->count();
    }

    private function leadSources(): array
    {
        $between = $this->period->between();
        $counts = Lead::whereBetween('created_at', $between)
            ->get(['lead_source'])
            ->countBy(fn (Lead $lead) => filled($lead->lead_source) ? $lead->lead_source : self::LEAD_SOURCE_NOT_SET);

        $sources = [...Lead::getSourceOptions(), self::LEAD_SOURCE_NOT_SET];

        return [
            'leads' => array_map(fn (string $source) => ['source' => $source, 'count' => (int) ($counts[$source] ?? 0)], $sources),
            'prequalifications' => PreQualificationRequest::whereBetween('created_at', $between)->count(),
            'embedded_applications' => EmbedFormSubmission::whereBetween('created_at', $between)->count(),
        ];
    }

    private function partners(): array
    {
        $partners = fn () => Partner::excludingHost();

        return [
            'total' => $partners()->count(),
            'white_label' => $partners()->where('partner_type', Partner::PARTNER_TYPE_WHITE_LABEL)->count(),
            'certified' => $partners()->where('partner_type', Partner::PARTNER_TYPE_CERTIFIED)->count(),
            'new' => $partners()->whereBetween('created_at', $this->period->between())->count(),
            'on_trial' => $partners()->where('is_free_trail', true)
                ->whereDate('free_trail_end_date', '>=', $this->period->today->toDateString())
                ->count(),
            'suspended' => $partners()->where('status', Partner::STATUS_SUSPENDED)->count(),
        ];
    }

    /** Partners with deals funded or leads in the period, by funded volume. */
    private function topPartners(Collection $funded): array
    {
        $leads = Lead::whereBetween('created_at', $this->period->between())
            ->whereNotNull('partner_id')
            ->get(['partner_id'])
            ->countBy('partner_id');

        $fundedByPartner = $funded->groupBy('partner_id');
        $commissions = Commission::whereIn('funding_application_id', $funded->pluck('id'))
            ->get(['partner_id', 'partner_commission_amount', 'payment_status'])
            ->groupBy('partner_id');

        $ids = $fundedByPartner->keys()->merge($leads->keys())->unique();

        return Partner::excludingHost()
            ->whereIn('id', $ids)
            ->get(['id', 'company_name', 'partner_type'])
            ->map(function (Partner $partner) use ($leads, $fundedByPartner, $commissions) {
                $deals = $fundedByPartner[$partner->id] ?? collect();
                $shares = $commissions[$partner->id] ?? collect();

                return [
                    'id' => $partner->id,
                    'company_name' => $partner->company_name,
                    'partner_type' => $partner->partner_type,
                    'leads' => (int) ($leads[$partner->id] ?? 0),
                    'funded_deals' => $deals->count(),
                    'funded_amount' => $this->money($deals->sum('total_funded_amount')),
                    'commission_share' => $this->money($shares->sum('partner_commission_amount')),
                    'commission_paid' => $this->money($shares->where('payment_status', Commission::PAYMENT_STATUS_PAID)->sum('partner_commission_amount')),
                ];
            })
            ->sortBy([['funded_amount', 'desc'], ['leads', 'desc']])
            ->take(self::TOP_PARTNERS_LIMIT)
            ->values()
            ->all();
    }

    /** Funded volume and cash collected per month, for the charts. */
    private function monthly(): array
    {
        $months = $this->period->chartMonths(self::CHART_MONTHS);
        $first = $months[0];
        $last = end($months)->endOfMonth()->startOfDay();
        $tz = $this->period->timezone;

        $funded = $this->fundedIn($first, $last)
            ->groupBy(fn (FundingApplication $application) => $application->funded_date->format('Y-m'))
            ->map(fn (Collection $rows) => $rows->sum('total_funded_amount'));

        [$start, $end] = OwnerSummaryPeriod::utcRange($first, $last);
        $cash = $this->hostPayments()
            ->where('payments.status', Payment::STATUS_SUCCEEDED)
            ->where('payments.transaction_date', '>=', $start)
            ->where('payments.transaction_date', '<', $end)
            ->get(['payments.amount', 'payments.transaction_date'])
            ->groupBy(fn ($row) => CarbonImmutable::parse($row->transaction_date)->tz($tz)->format('Y-m'))
            ->map(fn (Collection $rows) => $rows->sum('amount'));

        return array_map(fn (CarbonImmutable $month) => [
            'month' => $month->format('Y-m'),
            'funded' => $this->money($funded[$month->format('Y-m')] ?? 0),
            'cash' => $this->money($cash[$month->format('Y-m')] ?? 0),
        ], $months);
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
