<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\User\User;
use Illuminate\Validation\Rule;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\BusinessEin;
use App\Rules\SafeUpload;

class UpdatePartnerRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A Tax ID is an EIN: the screen writes it XX-XXXXXXX, the column keeps the
     * nine digits. Anything that is not a writing of an EIN is left for
     * BusinessEin to refuse.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('tax_id')) {
            $this->merge(['tax_id' => BusinessEin::canonical($this->input('tax_id'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $baseUrl = preg_replace('/^https?:\/\//', '', config('app.base_website'));
        $partner = Partner::find($this->id);
        $wasFreeTrial = $partner ? (bool) $partner->is_free_trail : false;

        $isFreeTrialNew = $this->has('is_free_trail')
            ? ($this->input('is_free_trail') == 1 || $this->input('is_free_trail') === true || $this->input('is_free_trail') === 'true')
            : $wasFreeTrial;

        // Migrating from free trial to paid?
        $isMigrating = $wasFreeTrial && $this->has('is_free_trail') && !$isFreeTrialNew;

        $rules = [
            // ── Partner Info ────────────────────────────────────────────────
            'full_name'    => ['sometimes', 'required', 'string', 'max:255'],
            'company_name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('partners', 'company_name')->ignore($this->id)->whereNull('deleted_at'),
            ],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('partners', 'email')->ignore($this->id)->whereNull('deleted_at'),
                // Unique across every active login except the partner's own. This
                // used to let through any email already used inside the same
                // partner (e.g. one of its employees), which the update then
                // either crashed on or, via a lookup by email, overwrote. A
                // deleted login no longer holds its email: users.email has had
                // no unique index since 2026_03_06_114733.
                Rule::unique('users', 'email')->ignore(
                    User::where('entity_type', Partner::class)->where('entity_id', (int) $this->id)->value('id')
                )->whereNull('deleted_at'),
            ],
            'phone_number'               => ['sometimes', 'required', 'string', new UsPhoneNumber()],
            'status'                     => ['nullable', 'string', 'max:255'],
            'contact_person'             => ['nullable', 'string', 'max:255'],
            'contact_person_phone_number'=> ['nullable', 'string', new UsPhoneNumber()],
            'contact_person_email'       => ['nullable', 'email', 'max:255'],
            'contact_person_relation'    => ['nullable', 'string', 'max:255'],
            'partner_type'               => ['sometimes', 'required', 'string', 'max:255'],
            'state'                      => ['sometimes', 'nullable', 'string', 'max:100'],
            'city'                       => ['sometimes', 'nullable', 'string', 'max:100'],
            'country'                    => ['sometimes', 'required', 'string', 'max:100'],
            'postal_code'                => ['nullable', 'string', new UsPostalCode()],
            'address_line_1'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2'             => ['nullable', 'string', 'max:255'],
            'tax_id'                     => ['nullable', 'string', new BusinessEin()],
            'website'                    => ['nullable', 'string'],
            'joining_date'               => ['nullable', 'date'],
            'company_logo'               => ['nullable', SafeUpload::image()],
            'partner_image'              => ['nullable', SafeUpload::image()],
            'assign_to'                  => ['nullable', 'integer', 'exists:users,id'],
            'partner_domain'             => [
                Rule::requiredIf(function () use ($partner) {
                    return $partner && $partner->partner_type === Partner::PARTNER_TYPE_CERTIFIED;
                }),
                'nullable', 'string', 'min:4', 'max:50',
                'regex:/^[a-zA-Z](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/',
                Rule::notIn(Partner::RESTRICTED_DOMAINS),
                function ($attribute, $value, $fail) use ($baseUrl) {
                    $fullDomain = $value . '.' . $baseUrl;
                    if (Partner::where('partner_domain', $fullDomain)->where('id', '!=', $this->id)->whereNull('deleted_at')->exists()) {
                        $fail(__('partner.validation.partner_domain.unique'));
                    }
                },
            ],
            'is_free_trail' => ['nullable', 'boolean'],

            // ── Enrollment sub-object (only required when migrating off free trial) ──
            'enrollment'    => [$isMigrating ? 'required' : 'nullable', 'array'],
        ];

        if ($isFreeTrialNew) {
            if ($this->has('is_free_trail') || $this->has('free_trail_end_date')) {
                $rules['free_trail_end_date'] = ['required', 'date_format:Y-m-d'];
            } else {
                $rules['free_trail_end_date'] = ['nullable', 'date_format:Y-m-d'];
            }
            $rules['enrollment.enrollment_type']      = ['nullable', 'string', 'max:255'];
            $rules['enrollment.amount']               = ['nullable'];
            $rules['enrollment.monthly_platform_fee'] = ['nullable'];
        } else {
            $rules['free_trail_end_date'] = ['nullable', 'date_format:Y-m-d'];

            $rules['enrollment.plan_id']            = ['nullable', 'exists:subscription_plans,id'];
            $rules['enrollment.enrollment_type']    = [
                Rule::requiredIf($isMigrating), 'nullable', 'string', 'max:255',
            ];
            $rules['enrollment.amount'] = [
                Rule::requiredIf($isMigrating),
                'nullable', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/',
            ];
            $rules['enrollment.monthly_platform_fee'] = [
                Rule::requiredIf($isMigrating),
                'nullable', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/',
            ];

            $enrollmentType = strtolower(trim((string) ($this->input('enrollment.enrollment_type') ?? ($partner?->subscription?->enrollment_type ?? ''))));
            if ($enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG) {
                $rules['enrollment.down_payment']      = ['nullable', 'numeric', 'min:0'];
                $rules['enrollment.months']            = ['nullable', 'integer', 'min:1', 'max:24'];
                $rules['enrollment.monthly_amount']    = ['nullable', 'numeric', 'min:0'];
                $rules['enrollment.remaining_balance'] = ['nullable', 'numeric', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'full_name.required'                       => __('partner.validation.full_name.required'),
            'company_name.required'                    => __('partner.validation.company_name.required'),
            'email.required'                           => __('partner.validation.email.required'),
            'email.email'                              => __('partner.validation.email.email'),
            'email.unique'                             => __('partner.validation.email.unique'),
            'phone_number.required'                    => __('partner.validation.phone_number.required'),
            'partner_type.required'                    => __('partner.validation.partner_type.required'),
            'state.required'                           => __('partner.validation.state.required'),
            'city.required'                            => __('partner.validation.city.required'),
            'country.required'                         => __('partner.validation.country.required'),
            'address_line_1.required'                  => __('partner.validation.address_line_1.required'),
            'partner_domain.required'                  => __('partner.validation.partner_domain.required'),
            'partner_domain.min'                       => __('partner.validation.partner_domain.min'),
            'partner_domain.max'                       => __('partner.validation.partner_domain.max'),
            'partner_domain.regex'                     => __('partner.validation.partner_domain.regex'),
            'partner_domain.unique'                    => __('partner.validation.partner_domain.unique'),
            'partner_domain.not_in'                    => __('partner.validation.partner_domain.unique'),
            'free_trail_end_date.required'             => 'Trial end date is required when free trial is enabled.',
            'free_trail_end_date.after_or_equal'       => 'Trial end date must be today or a future date.',
            'enrollment.required'                      => 'Enrollment details are required when converting from free trial.',
            'enrollment.array'                         => 'Enrollment must be an object.',
            'enrollment.enrollment_type.required'      => __('partner.validation.enrollment_type_required'),
            'enrollment.amount.required'               => __('partner.validation.enrollment_amount_required'),
            'enrollment.amount.numeric'                => __('partner.validation.enrollment_amount_numeric'),
            'enrollment.amount.gt'                     => __('partner.validation.enrollment_amount_gt'),
            'enrollment.amount.regex'                  => __('partner.validation.enrollment_amount_regex'),
            'enrollment.monthly_platform_fee.required' => __('partner.validation.monthly_platform_fee_required'),
            'enrollment.monthly_platform_fee.numeric'  => __('partner.validation.monthly_platform_fee_numeric'),
            'enrollment.monthly_platform_fee.gt'       => __('partner.validation.monthly_platform_fee_gt'),
            'enrollment.monthly_platform_fee.regex'    => __('partner.validation.monthly_platform_fee_regex'),
        ];
    }
}
