<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use App\Rules\BusinessEin;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\DunsNumber;
use App\Models\Borrower\Borrower;
use App\Support\Profile\LockedFields;
use Illuminate\Support\Facades\Auth;

class UpdateBusinessInfoRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    /**
     * An EIN is nine digits; the hyphen is only how it is written. Reducing it
     * here means the column holds one form whichever way the caller sent it,
     * and that the rule below checks the number rather than the writing.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('business_ein')) {
            // canonical(), not normalize(): now the EIN is optional, letters
            // stripped to nothing would pass as "no EIN" instead of failing.
            $this->merge(['business_ein' => BusinessEin::canonical($this->input('business_ein'))]);
        }
    }

    public function rules(): array
    {
        return [
            'business_name' => 'required|string|max:255',
            // Optional; once saved it is locked like the rest (BUG-054).
            'business_ein' => ['nullable', new BusinessEin()],
            'business_phone' => ['nullable', 'string', new UsPhoneNumber()],
            'business_email' => 'required|email|max:255',
            'duns_number' => ['nullable', 'string', new DunsNumber()],
            'business_address' => 'required|string|max:255',
            'business_country' => 'required|string|max:255',
            'business_country_code' => 'required|string|max:5',
            'business_city' => 'required|string|max:255',
            'business_state' => 'required|string|max:255',
            'business_zip_code' => ['required', 'string', new UsPostalCode()],
            'business_website' => 'nullable|max:255',
            'business_entity_type' => 'required|string|max:255',
            'business_entity_type_note' => 'required_if:business_entity_type,' . config('borrower.entity_type_other') . '|nullable|string|max:255',
            'business_industry_type' => 'required|string|max:255',
            'business_industry_type_note' => 'required_if:business_industry_type,' . config('borrower.industry_type_other') . '|nullable|string|max:255',
            'business_start_date' => 'required|date',
        ];
    }

    /**
     * A saved, locked field may only come back unchanged: changing it is a
     * Change Request for the host to approve (BUG-054). The portal makes these
     * fields read-only; this is what holds when that is bypassed.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Read fresh: the lock is judged against what is stored now.
            $borrower = Auth::user()?->entity()->first();
            if (!$borrower instanceof Borrower) {
                return;
            }

            $changed = LockedFields::changed($borrower->getAttributes(), $this->all(), Borrower::LOCKED_BUSINESS_FIELDS);
            foreach ($changed as $field) {
                $validator->errors()->add($field, __('borrower_validation.locked_field_change_request'));
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => __('borrower_validation.business_name_required'),
            'business_name.string' => __('borrower_validation.business_name_string'),
            'business_name.max' => __('borrower_validation.business_name_max'),
            'business_ein.required' => __('borrower_validation.business_ein_required'),
            'business_ein.string' => __('borrower_validation.business_ein_string'),
            'business_ein.max' => __('borrower_validation.business_ein_max'),
            'business_ein.size' => __('borrower_validation.business_ein_size'),
            'business_phone.string' => __('borrower_validation.business_phone_string'),
            'business_phone.max' => __('borrower_validation.business_phone_max'),
            'business_address.required' => __('borrower_validation.business_address_required'),
            'business_address.string' => __('borrower_validation.business_address_string'),
            'business_address.max' => __('borrower_validation.business_address_max'),
            'business_city.required' => __('borrower_validation.business_city_required'),
            'business_email.required' => __('borrower_validation.business_email_required'),
            'business_email.email' => __('borrower_validation.business_email_email'),
            'business_email.max' => __('borrower_validation.business_email_max'),
            'duns_number.string' => __('borrower_validation.duns_number_string'),
            'duns_number.max' => __('borrower_validation.duns_number_max'),
            'duns_number.size' => __('borrower_validation.duns_number_size'),
            'business_city.string' => __('borrower_validation.business_city_string'),
            'business_city.max' => __('borrower_validation.business_city_max'),
            'business_state.required' => __('borrower_validation.business_state_required'),
            'business_state.string' => __('borrower_validation.business_state_string'),
            'business_state.max' => __('borrower_validation.business_state_max'),
            'business_zip_code.required' => __('borrower_validation.business_zip_code_required'),
            'business_zip_code.string' => __('borrower_validation.business_zip_code_string'),
            'business_entity_type.required' => __('borrower_validation.business_entity_type_required'),
            'business_entity_type.string' => __('borrower_validation.business_entity_type_string'),
            'business_entity_type.max' => __('borrower_validation.business_entity_type_max'),
            'business_entity_type_note.required_if' => __('borrower_validation.business_entity_type_note_required_if'),
            'business_website.url' => __('borrower_validation.business_website_url'),
            'business_website.max' => __('borrower_validation.business_website_max'),
            'business_industry_type.required' => __('borrower_validation.business_industry_type_required'),
            'business_industry_type.string' => __('borrower_validation.business_industry_type_string'),
            'business_industry_type.max' => __('borrower_validation.business_industry_type_max'),
            'business_industry_type_note.required_if' => __('borrower_validation.business_industry_type_note_required_if'),
        ];
    }
}