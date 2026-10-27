<?php

namespace App\Http\Resources\FCH\Common\Commission;

use App\Services\FCH\Common\Commission\CommissionPayoutService;
use App\Services\FCH\Common\Commission\CommissionViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Only the money this viewer may see is sent at all - hiding it on the
        // screen is not enough (see CommissionViewer).
        $viewer = CommissionViewer::for($request->user());
        $owner = $viewer->seesShares();
        $host = $viewer->is(CommissionViewer::HOST_OWNER);

        // filter() drops the when() values left out: the paginated list calls
        // toArray() directly, which would otherwise send them as empty objects.
        return $this->filter([
            'id' => $this->id,
            'borrower' => $this->fundingApplication?->full_name,
            'partner' => $this->partner?->full_name,
            'funding_application_id' => $this->funding_application_id,
            'total_commission' => $this->when($owner, $this->total_commission),
            'admin_commission' => $this->when($host, $this->admin_commission),
            'partner_commission' => $this->when($owner, $this->partner_commission),
            'total_commission_amount' => $this->when($owner, $this->total_commission_amount),
            'admin_commission_amount' => $this->when($host, $this->admin_commission_amount),
            'partner_commission_amount' => $this->when($owner, $this->partner_commission_amount),
            // A host staff member's own payout from the deal: what was paid, or
            // what the Pay button would pay.
            'my_payout' => $this->when(
                $viewer->is(CommissionViewer::HOST_STAFF),
                fn () => app(CommissionPayoutService::class)->forCommission($this->resource)['amount'] ?? null
            ),
            'commission_status' => $this->commission_status,
            'requested_amount' => $this->fundingApplication?->requested_amount,
            'funded_amount' => $this->fundingApplication?->total_funded_amount,
            'payment_status' => $this->payment_status,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'conversion_rate' => $this->conversion_rate,
            'note' => $this->note,
            // The viewer's day (X-Timezone), not the UTC one (BUG-120).
            'date' => toTimezone($this->created_at, null, __('commission.list_date_format')),
        ]);
    }
}
