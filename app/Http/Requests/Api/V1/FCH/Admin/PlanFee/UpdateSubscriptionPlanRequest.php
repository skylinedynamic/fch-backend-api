<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\PlanFee;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;

/**
 * Plan & Fee > change a plan's price. A plan always costs something: the
 * amount is required and above 0 (BUG-096 - 0 and an empty amount used to be
 * saved as $0.00). Each refusal names its field, one message per field, for
 * the screen to show under it.
 */
class UpdateSubscriptionPlanRequest extends BaseApiRequest
{
    /** decimal(10, 2): the largest amount the columns hold. */
    private const MAX_AMOUNT = 99999999.99;

    /** Only the host's team changes plan prices - refused before any value is checked. */
    public function authorize(): bool
    {
        $user = $this->user();
        $partner = $user?->partner;
        if (!$partner && ($user?->entity_type === Partner::class)) {
            $partner = $user->entity;
        }

        return (bool) $partner?->is_host;
    }

    public function rules(): array
    {
        return [
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:' . self::MAX_AMOUNT],
            // Only Partner Enrollment plans have a platform fee; when one is sent it
            // is filled in and above 0.
            'platform_fee' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:' . self::MAX_AMOUNT],
            'total_installment_count_upto' => ['nullable', 'integer', 'min:1'],
            'total_seat_count' => ['nullable', 'integer', 'min:0'],
            'include_existing_subscription' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        $max = ['max' => number_format(self::MAX_AMOUNT, 2)];

        return [
            'total_amount.required' => __('plan_fee.total_amount_required'),
            'total_amount.numeric' => __('plan_fee.total_amount_numeric'),
            'total_amount.gt' => __('plan_fee.total_amount_gt'),
            'total_amount.max' => __('plan_fee.total_amount_max', $max),
            'platform_fee.numeric' => __('plan_fee.platform_fee_numeric'),
            'platform_fee.required' => __('plan_fee.platform_fee_required'),
            'platform_fee.gt' => __('plan_fee.platform_fee_gt'),
            'platform_fee.max' => __('plan_fee.platform_fee_max', $max),
            'total_installment_count_upto.integer' => __('plan_fee.installments_integer'),
            'total_installment_count_upto.min' => __('plan_fee.installments_min'),
            'total_seat_count.integer' => __('plan_fee.seats_integer'),
            'total_seat_count.min' => __('plan_fee.seats_min'),
        ];
    }
}
