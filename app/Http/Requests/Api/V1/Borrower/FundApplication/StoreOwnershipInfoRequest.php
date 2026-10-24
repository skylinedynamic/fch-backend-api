<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\UsPostalCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Rules\Ssn;
use App\Rules\UsPhoneNumber;
use Illuminate\Validation\Validator;

class StoreOwnershipInfoRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An SSN is nine digits; the hyphens are only how it is written. This used
     * to demand bare digits, and the value is stored as sent, so a written SSN
     * is reduced here - one stored form - while anything else is left for the
     * rule to refuse.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('ssn')) {
            $merge['ssn'] = Ssn::canonical($this->input('ssn'));
        }

        // The co-borrowers saved with this step are stored as sent too.
        $coBorrowers = $this->input('co_borrowers');
        if (is_array($coBorrowers)) {
            foreach ($coBorrowers as $index => $coBorrower) {
                if (is_array($coBorrower) && array_key_exists('ssn', $coBorrower)) {
                    $coBorrowers[$index]['ssn'] = Ssn::canonical($coBorrower['ssn']);
                }
            }
            $merge['co_borrowers'] = $coBorrowers;
        }

        if ($merge) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['nullable', Rule::exists('funding_applications', 'id')->where('borrower_id', Auth::user()?->entity_id)],
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date',
            'ssn' => ['required', 'string', new Ssn()],
            'country' => 'nullable|string|max:255',
            'phone_number' => ['required', 'string', new UsPhoneNumber()],
            'citizenship_status' => 'required|string|max:255',
            'street_address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zip_code' => ['required', 'string', new UsPostalCode()],
            'ownership' => 'required|numeric',
            'has_co_borrower' => 'nullable|boolean',
            'co_borrowers' => 'nullable|array',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.exists' => __('borrower_validation.funding_application_id_exists'),
            'first_name.string' => __('borrower_validation.first_name_string'),
            'first_name.max' => __('borrower_validation.first_name_max'),
            'last_name.string' => __('borrower_validation.last_name_string'),
            'last_name.max' => __('borrower_validation.last_name_max'),
            'date_of_birth.date' => __('borrower_validation.date_of_birth_date'),
            'ssn.string' => __('borrower_validation.ssn_string'), 
            'ssn.digits' => __('borrower_validation.ssn_digits'),
            'country.string' => __('borrower_validation.country_string'),
            'country.max' => __('borrower_validation.country_max'),
            'citizenship_status.string' => __('borrower_validation.citizenship_status_string'),
            'citizenship_status.max' => __('borrower_validation.citizenship_status_max'),
            'street_address.string' => __('borrower_validation.home_address_string'),
            'street_address.max' => __('borrower_validation.home_address_max'),
            'city.string' => __('borrower_validation.city_string'),
            'city.max' => __('borrower_validation.city_max'),
            'state.string' => __('borrower_validation.state_string'),
            'state.max' => __('borrower_validation.state_max'),
            'zip_code.string' => __('borrower_validation.zip_code_string'),
            'ownership.numeric' => __('borrower_validation.ownership_numeric'),
            'has_co_borrower.boolean' => __('borrower_validation.has_co_borrower_boolean'),
        ];
    }
    /**
     * The co-borrowers saved with this step, in the shared formats when given.
     *
     * Checked here rather than as `co_borrowers.*.ssn` rules: nested rules would
     * cut each co-borrower in validated() down to the fields that have one, and
     * the service saves the rest (names, address, ownership) from it too.
     * Optional - a co-borrower may be saved part-filled.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $formats = [
                'ssn' => new Ssn(),
                'zip_code' => new UsPostalCode(),
                'mobile_phone' => new UsPhoneNumber(),
                'home_phone' => new UsPhoneNumber(),
            ];

            foreach ((array) $this->input('co_borrowers', []) as $index => $coBorrower) {
                foreach ($formats as $field => $rule) {
                    $key = "co_borrowers.{$index}.{$field}";
                    $rule->validate($key, is_array($coBorrower) ? ($coBorrower[$field] ?? null) : null, fn ($message) => $validator->errors()->add($key, $message));
                }
            }
        }];
    }
}
