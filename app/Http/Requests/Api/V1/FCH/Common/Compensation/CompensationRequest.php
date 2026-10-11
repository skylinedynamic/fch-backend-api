<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Compensation;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Compensation\CompensationSetting;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Shared by the store and update endpoints: both submit an entity's complete
 * compensation setup, so the payload — and therefore the rules — are identical.
 */
class CompensationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Tolerate `compensations` arriving as a JSON string, so a caller posting
     * multipart/form-data (which can't express nested arrays) is validated
     * row by row rather than against one opaque string.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('compensations'))) {
            $decoded = json_decode($this->input('compensations'), true);

            $this->merge([
                'compensations' => (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [],
            ]);
        }
    }

    public function rules(): array
    {
        $paidBy = $this->input('paid_by');
        $isRateBased = in_array($paidBy, [CompensationSetting::PAID_BY_HOURLY, CompensationSetting::PAID_BY_SALARY], true);
        $isPerDeal = $paidBy === CompensationSetting::PAID_BY_PER_DEAL;

        return [
            'entity_type' => ['required', Rule::in(array_keys(CompensationSetting::ENTITY_TYPES))],
            'entity_id' => ['required', 'integer', 'min:1'],

            'paid_by' => ['required', Rule::in(CompensationSetting::PAID_BY_OPTIONS)],

            'compensations' => [
                Rule::requiredIf($paidBy && $paidBy !== CompensationSetting::PAID_BY_NONE),
                'array',
            ],

            // Per Deal is the only setup broken down by service; an hourly rate
            // or a salary is a single figure that applies to the whole role.
            'compensations.*.service' => [$isPerDeal ? 'required' : 'nullable', 'string', 'max:255'],

            'compensations.*.payout_type' => [
                $isPerDeal ? 'nullable' : 'prohibited',
                Rule::in(CompensationSetting::PAYOUT_TYPE_OPTIONS),
            ],

            // A rate or salary with no figure is meaningless, but a Per Deal
            // service may legitimately be selected before its payout is filled in.
            'compensations.*.amount' => [$isRateBased ? 'required' : 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * A Commission row's amount is a percentage, so it has an upper bound a
     * flat dollar amount doesn't — which the per-field rules can't express,
     * since the bound depends on a sibling field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('compensations', []) as $index => $row) {
                $isCommission = ($row['payout_type'] ?? null) === CompensationSetting::PAYOUT_TYPE_COMMISSION;

                if ($isCommission && isset($row['amount']) && is_numeric($row['amount']) && $row['amount'] > 100) {
                    $validator->errors()->add(
                        "compensations.{$index}.amount",
                        __('compensation.commission_percentage_max')
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'entity_type.required' => __('compensation.entity_type_required'),
            'entity_type.in' => __('compensation.entity_type_invalid'),
            'entity_id.required' => __('compensation.entity_id_required'),
            'paid_by.required' => __('compensation.paid_by_required'),
            'paid_by.in' => __('compensation.paid_by_invalid'),
            'compensations.required' => __('compensation.compensations_required'),
            'compensations.*.service.required' => __('compensation.service_required'),
            'compensations.*.payout_type.prohibited' => __('compensation.payout_type_prohibited'),
            'compensations.*.amount.required' => __('compensation.amount_required'),
        ];
    }
}
