<?php

namespace App\Http\Resources\FCH\Common\Commission;

use App\Services\FCH\Common\Commission\CommissionPayoutService;
use App\Services\FCH\Common\Commission\CommissionViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Only the money this viewer may see is sent (see CommissionViewer).
        $viewer = CommissionViewer::for($request->user());
        $owner = $viewer->seesShares();
        $host = $viewer->is(CommissionViewer::HOST_OWNER);

        return [
            'id' => $this->id,
            'viewer' => $viewer->role,
            'commission_status' => $this->commission_status,
            'commission_payment_status' => $this->payment_status,
            'payout_date' => $this->payout_date ? $this->payout_date->format('M d, Y') : null,
            'total_commission' => $this->when($owner, $this->total_commission),
            'admin_commission' => $this->when($host, $this->admin_commission),
            'partner_commission' => $this->when($owner, $this->partner_commission),
            'total_commission_amount' => $this->when($owner, $this->total_commission_amount),
            'admin_commission_amount' => $this->when($host, $this->admin_commission_amount),
            'partner_commission_amount' => $this->when($owner, $this->partner_commission_amount),
            'commission_note' => $this->note,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'conversion_rate' => $this->conversion_rate, 

            'borrower_id' => $this->borrower_id,
            'borrower_name' => $this->fundingApplication?->full_name,
            'borrower_business_name' => $this->fundingApplication?->business_name,
            'borrower_business_email' => $this->fundingApplication?->business_email,
            'borrower_business_phone_no' => $this->fundingApplication?->business_phone_no,
            'borrower_business_country' => $this->fundingApplication?->business_country,
            'borrower_business_address' => $this->fundingApplication?->business_address,
            'borrower_business_city' => $this->fundingApplication?->business_city,
            'borrower_business_state' => $this->fundingApplication?->business_state,
            'business_postal_code' => $this->fundingApplication?->business_postal_code,

            'funding_application_id' => $this->fundingApplication?->id,
            'funding_requested_amount' => $this->fundingApplication?->requested_amount,
            'funded_amount' => $this->fundingApplication?->total_funded_amount,
            'funding_status' => $this->fundingApplication?->status,
            'funding_status_note' => $this->fundingApplication?->status_note,
            'funding_industry_type' => $this->fundingApplication?->industry_type,
            'funding_entity_type' => $this->fundingApplication?->entity_type,
            'funding_purpose_of_funds' => $this->fundingApplication?->purpose_of_funds,
            'funding_preferred_funding_type' => $this->fundingApplication?->preferred_funding_type,
            'funded_date' => $this->fundingApplication?->funded_date ? $this->fundingApplication?->funded_date->format('M d, Y') : null,

            'partner_id' => $this->partner_id,
            'partner_name' => $this->partner?->full_name,
            'partner_company_name' => $this->partner?->company_name,
            'partner_email' => $this->partner?->email,
            'partner_phone_number' => $this->partner?->phone_number,
            'partner_type' => $this->partner?->partner_type,
            'partner_logo' => $this->partner?->partner_image ? url(encodeUrl($this->partner->partner_image)) : null,

            // What the client's assigned user earns from the deal — clients of
            // the host partner only, otherwise null.
            // Partner staff are paid nothing per deal, so get no payout either.
            'payout' => $this->when(
                !$viewer->is(CommissionViewer::PARTNER_STAFF),
                fn () => $this->payoutFor($viewer)
            ),
        ];
    }

    /** The payout, with the Pay button only for those who may pay (see CommissionViewer::canPay). */
    private function payoutFor(CommissionViewer $viewer): ?array
    {
        $payout = app(CommissionPayoutService::class)->forCommission($this->resource);

        if ($payout && !$viewer->canPay()) {
            $payout['can_pay'] = false;
        }

        return $payout;
    }
}
