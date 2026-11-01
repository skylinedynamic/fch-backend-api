<?php

namespace App\Services\FCH\Common\Commission;

use App\Http\Middleware\EnsureHostPartner;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Commission\CommissionPayout;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is looking at commissions, and so which deals and which money they see.
 * The one place the Commissions list, a commission's page and the dashboard
 * card ask, so the three cannot disagree:
 *
 *   host owner     every deal and the host's share (admin commission), plus
 *                  what has been paid out of it to the host's staff. Host
 *                  staff who manage commissions (MANAGE_PERMISSION) see this too.
 *   host staff     only the deals of clients assigned to them, and only their
 *                  own payout from each (see CommissionPayoutService)
 *   partner owner  the partner's deals and its share - never the host's
 *   partner staff  only the deals of clients assigned to them, with no
 *                  commission money: the system pays them nothing per deal
 *
 * An owner is the login of the Partner itself; any other login of a partner
 * (an employee, a plain user) is staff.
 */
final class CommissionViewer
{
    public const HOST_OWNER = 'host_owner';
    public const HOST_STAFF = 'host_staff';
    public const PARTNER_OWNER = 'partner_owner';
    public const PARTNER_STAFF = 'partner_staff';

    private function __construct(
        public readonly User $user,
        public readonly string $role,
    ) {
    }

    /** Host staff holding this manage commissions - pay them out - so see the host's whole book. */
    public const MANAGE_PERMISSION = 'update commission';

    public static function for(User $user): self
    {
        $isOwner = $user->entity_type === Partner::class
            || (EnsureHostPartner::allows($user) && $user->checkPermissionTo(self::MANAGE_PERMISSION, 'api'));

        $role = EnsureHostPartner::allows($user)
            ? ($isOwner ? self::HOST_OWNER : self::HOST_STAFF)
            : ($isOwner ? self::PARTNER_OWNER : self::PARTNER_STAFF);

        return new self($user, $role);
    }

    public function is(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Owners see a share of each deal; host staff see their payout; partner staff no money. */
    public function seesShares(): bool
    {
        return $this->is(self::HOST_OWNER, self::PARTNER_OWNER);
    }

    /** Paying a commission out is the host's: its owner, or staff who manage commissions. */
    public function canPay(): bool
    {
        return $this->role === self::HOST_OWNER;
    }

    /** The share column an owner's totals are counted in. */
    public function shareField(): string
    {
        return $this->role === self::HOST_OWNER ? 'admin_commission_amount' : 'partner_commission_amount';
    }

    /** Limit a commission query to the deals this viewer may see. */
    public function scope(Builder $query): Builder
    {
        $assignedToMe = fn (Builder $q) => $q->whereHas(
            'borrower',
            fn ($b) => $b->withTrashed()->where('assign_id', $this->user->id)
        );

        // A partner's commission shows once the host closes the application as
        // Closed Completed; before that the host may still change the rates.
        // Trashed applications too: a deal of a client deleted since still counts.
        $partnersOwn = fn (Builder $q) => $q
            ->where('commissions.partner_id', $this->user->partner_id)
            ->whereHas(
                'fundingApplication',
                fn ($a) => $a->withTrashed()->where('status', FundingApplication::CLOSED_COMPLETED)
            );

        return match ($this->role) {
            self::HOST_OWNER => $query,
            self::HOST_STAFF => $assignedToMe($query),
            self::PARTNER_OWNER => $partnersOwn($query),
            self::PARTNER_STAFF => $assignedToMe($partnersOwn($query)),
        };
    }

    /**
     * The summary of the deals in $query, in this viewer's terms. Shared by the
     * Commissions list cards and the dashboard card.
     */
    public function summarize(Builder $query): array
    {
        $deals = [
            'viewer' => $this->role,
            'total_deals' => (clone $query)->count(),
            'completed_deals' => (clone $query)->where('commission_status', Commission::STATUS_COMPLETED)->count(),
            'total_funded_amount' => $this->fundedAmount($query),
        ];

        if ($this->role === self::PARTNER_STAFF) {
            return $deals;
        }

        if ($this->role === self::HOST_STAFF) {
            [$expectedAmount, $expectedDeals] = $this->expectedPayout($query);

            return $deals + [
                'my_paid_amount' => $this->paidToStaff($query, $this->user->id),
                'my_expected_amount' => $expectedAmount,
                'my_expected_deals' => $expectedDeals,
            ];
        }

        $share = $this->shareField();
        $byPayment = fn (string $status) => round((float) (clone $query)->where('payment_status', $status)->sum($share), 2);

        $summary = $deals + [
            'total_commission_amount' => round((float) (clone $query)->sum($share), 2),
            'paid_amount' => $byPayment(Commission::PAYMENT_STATUS_PAID),
            'pending_amount' => $byPayment(Commission::PAYMENT_STATUS_PENDING),
            'unpaid_amount' => $byPayment(Commission::PAYMENT_STATUS_UNPAID),
            'avg_commission_rate' => round((float) ((clone $query)->avg(
                $this->role === self::HOST_OWNER ? 'admin_commission' : 'partner_commission'
            ) ?? 0), 2),
        ];

        if ($this->role === self::HOST_OWNER) {
            $summary['partner_commission_amount'] = round((float) (clone $query)->sum('partner_commission_amount'), 2);
            $summary['paid_to_staff'] = $this->paidToStaff($query);
            // The whole commission on these deals, the host's and partners' shares together.
            $summary['deal_commission_amount'] = round((float) (clone $query)->sum('total_commission_amount'), 2);
            // What the host still has to pay out on funded deals: each partner's
            // share and each assigned staff member's payout - what Pay would pay.
            $summary['payouts_due_amount'] = $this->expectedPayout($query)[0];
        }

        return $summary;
    }

    private function fundedAmount(Builder $query): float
    {
        return round((float) FundingApplication::withTrashed()
            ->whereIn('id', (clone $query)->select('commissions.funding_application_id'))
            ->sum('total_funded_amount'), 2);
    }

    /** Paid out to staff on these deals (to one of them, when $userId is given) - what the invoices say. */
    private function paidToStaff(Builder $query, ?int $userId = null): float
    {
        $payouts = CommissionPayout::query()
            ->whereIn('commission_id', (clone $query)->select('commissions.id'))
            ->where('entity_type', CommissionPayout::PAYEE_TYPES['user'])
            ->when($userId, fn ($q) => $q->where('entity_id', $userId))
            ->select('invoice_id');

        return round((float) Invoice::whereIn('id', $payouts)->sum('payment_amount'), 2);
    }

    /**
     * What is owed on funded deals not paid out yet - the same amount the Pay
     * button would pay: to a host staff member, their own payouts; to the host,
     * every partner's share and staff payout. Returns [amount, number of deals].
     */
    private function expectedPayout(Builder $query): array
    {
        $payouts = app(CommissionPayoutService::class);

        $amounts = (clone $query)
            ->where('payment_status', '!=', Commission::PAYMENT_STATUS_PAID)
            ->whereDoesntHave('payout')
            ->whereHas('fundingApplication', fn ($q) => $q->where('status', FundingApplication::CLOSED_COMPLETED))
            ->with(['borrower' => fn ($q) => $q->withTrashed(), 'borrower.partner', 'borrower.assignedUser', 'partner', 'fundingApplication', 'payout'])
            ->get()
            ->map(fn (Commission $commission) => (float) ($payouts->forCommission($commission)['amount'] ?? 0))
            ->filter(fn (float $amount) => $amount > 0);

        return [round($amounts->sum(), 2), $amounts->count()];
    }
}
