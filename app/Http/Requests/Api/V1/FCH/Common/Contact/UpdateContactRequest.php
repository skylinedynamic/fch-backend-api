<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Contact;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;

class UpdateContactRequest extends BaseApiRequest
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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            // Stored as sent, so the written form is still required here.
            'mobile_number' => ['required', 'string', 'regex:/^\(\d{3}\) \d{3}-\d{4}$/', new UsPhoneNumber()],
            'email' => [
                'required',
                'max:255',
                Rule::unique('contacts')->where(function ($query) {
                    return $query->where('partner_id', $this->user()->partner_id)
                        ->whereNull('deleted_at');
                })->ignore($this->route('contact'))
            ],
            'state' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', new UsPostalCode()],
            'website' => ['nullable'],
            'notes' => ['nullable', 'string'],
            'image' => ['nullable'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_referral_partner' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => __('contact.first_name_required'),
            'mobile_number.required' => __('contact.mobile_number_required'),
            'mobile_number.regex' => __('contact.mobile_number_invalid'),
            'email.required' => __('contact.email_required'),
            'email.email' => __('contact.email_valid'),
            'email.unique' => __('contact.email_taken'),
        ];
    }
}
