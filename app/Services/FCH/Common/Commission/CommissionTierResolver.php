<?php

namespace App\Services\FCH\Common\Commission;

use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which funding-application count tier a partner's deal falls in (Partner
 * profile › Commissions).
 *
 * A deal's position is 1-based and counts funded deals only (Closed
 * (Completed)), all-time, so "0-10" holds deals #1 to #10. Each deal keeps the
 * tier of its own position: crossing into a higher tier never re-prices deals
 * already made.
 */
class CommissionTierResolver
{
    /**
     * The tier holding $position, or null when none does.
     *
     * @param Collection<int, object{min_count:int, max_count:?int}> $tiers
     */
    public static function pick(Collection $tiers, int $position): ?object
    {
        return $tiers
            ->sortBy('min_count')
            ->first(fn ($tier) => $position >= (int) $tier->min_count
                && ($tier->max_count === null || $position <= (int) $tier->max_count));
    }

    /** How many of the partner's clients' funding applications have been funded. */
    public function partnerFundedCount(Partner $partner): int
    {
        return $this->fundedOf($partner)->count();
    }

    /**
     * A partner's deal position: its own place among the partner's funded
     * deals when already funded (by funded date, then id), otherwise the
     * partner's next deal.
     */
    public function partnerPosition(Partner $partner, FundingApplication $application): int
    {
        $funded = $this->fundedOf($partner);

        if ($application->status !== FundingApplication::CLOSED_COMPLETED) {
            return $funded->count() + 1;
        }

        $date = $application->funded_date?->toDateString();

        return $funded
            ->where(function (Builder $query) use ($application, $date) {
                $date === null
                    ? $query->whereNull('funded_date')->where('id', '<=', $application->id)
                    : $query->whereNull('funded_date') // undated deals sort first
                        ->orWhereDate('funded_date', '<', $date)
                        ->orWhere(fn (Builder $q) => $q->whereDate('funded_date', $date)->where('id', '<=', $application->id));
            })
            ->count();
    }

    private function fundedOf(Partner $partner): Builder
    {
        return FundingApplication::query()
            ->where('partner_id', $partner->id)
            ->where('status', FundingApplication::CLOSED_COMPLETED);
    }
}
