<?php

namespace App\Http\Resources\FCH\Partner;

use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Partner */
class PartnerDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->users->first()->id ?? null,
            'assigned_user_id' => $this->assignedUser->id ?? null,
            'assigned_user_name' => $this->assignedUser->name ?? null,
            'full_name' => $this->full_name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'contact_person' => $this->contact_person,
            'contact_person_email' => $this->contact_person_email,
            'contact_person_phone_number' => $this->contact_person_phone_number,
            'contact_person_relation' => $this->contact_person_relation,
            'partner_type' => $this->partner_type,
            'status' => $this->status,
            'state' => $this->state,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'country' => $this->country,
            'country_code' => $this->country_code,
            'joining_date' => toTimezone($this->joining_date, null, 'Y-m-d'),
            'company_logo' => $this->company_logo,
            'partner_image' => $this->partner_image,
            'partner_domain' => $this->partner_domain,
            'borrower_domain' => $this->borrower_domain,
            'active_custom_url' => (bool) $this->active_custom_url,
            'is_host' => $this->is_host ?? false,
            'has_sign' => $this->has_sign,
            'tax_id' => $this->tax_id ?? null, // Assuming model accessor or field exists
            'website' => $this->website ?? null, // Assuming model accessor or field exists
            'enrollment_details' => [
                'plan_id' => $this->subscription?->subscription_plan_id !== null ? (int) $this->subscription->subscription_plan_id : null,
                'enrollment_type' => $this->subscription?->enrollment_type ?? null,
                'payable_amount' => $this->subscription?->payable_amount !== null ? (float) $this->subscription->payable_amount : null,
                'payable_amount_formatted' => currencyFormat($this->subscription?->payable_amount),
                'total_subscription_amount' => $this->subscription?->total_subscription_amount !== null ? (float) $this->subscription->total_subscription_amount : null,
                'total_subscription_amount_formatted' => currencyFormat($this->subscription?->total_subscription_amount),
                'monthly_platform_fee' => $this->subscription?->monthly_platform_fee !== null ? (float) $this->subscription->monthly_platform_fee : null,
                'monthly_platform_fee_formatted' => currencyFormat($this->subscription?->monthly_platform_fee),
                'enrollment_amount' => $this->subscription?->payable_amount !== null ? (float) $this->subscription->payable_amount : null,
                'enrollment_amount_formatted' => currencyFormat($this->subscription?->payable_amount),
                'installment_applied' => $this->subscription && $this->subscription->installment_count > 0,
                'installment_down_payment' => $this->subscription?->down_payment !== null ? (float) $this->subscription->down_payment : 0.0,
                'installment_down_payment_formatted' => currencyFormat($this->subscription?->down_payment),
                'installment_months' => $this->subscription?->installment_count ?? 0,
                'installment_monthly_amount' => $this->subscription?->subscription_amount_per_month !== null ? (float) $this->subscription->subscription_amount_per_month : 0.0,
                'installment_monthly_amount_formatted' => currencyFormat($this->subscription?->subscription_amount_per_month),
                'installment_remaining_balance' => $this->subscription
                    ? (float) ($this->subscription->payable_amount - $this->subscription->down_payment)
                    : 0.0,
                'installment_remaining_balance_formatted' => $this->subscription
                    ? currencyFormat($this->subscription->payable_amount - $this->subscription->down_payment)
                    : currencyFormat(0),
                'subscription_start_date' => toTimezone($this->subscription?->subscription_start_date, null, 'Y-m-d'),
                'subscription_end_date' => toTimezone($this->subscription?->subscription_end_date, null, 'Y-m-d'),
            ],
            'is_free_trail' => $this->is_free_trail ? true : false,
            'free_trail_end_date' => toTimezone($this->free_trail_end_date, null, 'Y-m-d'),
            'subscriptions' => $this->subscriptions->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'partner_type' => $sub->partner_type,
                    'enrollment_type' => $sub->enrollment_type,
                    'total_subscription_amount' => currencyFormat($sub->total_subscription_amount),
                    'payable_amount' => currencyFormat($sub->payable_amount),
                    'monthly_platform_fee' => currencyFormat($sub->monthly_platform_fee),
                    'subscription_start_date' => toTimezone($sub->subscription_start_date, null, 'Y-m-d'),
                    'subscription_end_date' => toTimezone($sub->subscription_end_date, null, 'Y-m-d'),
                    'status' => $sub->status,
                ];
            }),
        ];
    }
}
