<?php

namespace App\Http\Requests\Api\V1\Borrower\PreQualification;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\UsPhoneNumber;

class PersonalInfoRequest extends BaseApiRequest
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
    public function rules(): array
    {
        return [
            'id' => ['nullable','exists:pre_qualification_requests,id'],
            'first_name' => ['required','string','max:255'],
            'last_name' => ['required','string','max:255'],
            'business_website' => ['nullable','url','max:255'],
            'email' => ['required','email','max:255'],
            'phone_number' => ['required', 'string', new UsPhoneNumber()],
            'state_of_incorporation' => ['required','string','max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => __('borrower_validation.first_name_required'),
            'first_name.string' => __('borrower_validation.first_name_string'),
            'first_name.max' => __('borrower_validation.first_name_max'),

            'last_name.required' => __('borrower_validation.last_name_required'),
            'last_name.string' => __('borrower_validation.last_name_string'),
            'last_name.max' => __('borrower_validation.last_name_max'),

            'business_website.url' => __('borrower_validation.business_website_url'),
            'business_website.max' => __('borrower_validation.business_website_max'),

            'email.required' => __('borrower_validation.email_required'),
            'email.email' => __('borrower_validation.email_email'),
            'email.max' => __('borrower_validation.email_max'),

            'phone_number.required' => __('borrower_validation.phone_required'),
            'phone_number.string' => __('borrower_validation.phone_string'),
            'phone_number.max' => __('borrower_validation.phone_max'),

            'state_of_incorporation.required' => __('borrower_validation.state_of_incorporation_required'),
            'state_of_incorporation.string' => __('borrower_validation.state_of_incorporation_string'),
            'state_of_incorporation.max' => __('borrower_validation.state_of_incorporation_max'),
        ];
    }
}
