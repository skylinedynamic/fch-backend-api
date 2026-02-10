<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Contact;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

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
            'company_name' => ['required', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'max:20'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('contacts')->where(function ($query) {
                    return $query->where('partner_id', $this->user()->partner_id)
                        ->whereNull('deleted_at');
                })->ignore($this->route('contact'))
            ],
            'state' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'website' => ['nullable'],
            'notes' => ['nullable', 'string'],
            'image' => ['nullable'],
            'address' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => __('contact.first_name_required'),
            'company_name.required' => __('contact.company_name_required'),
            'mobile_number.required' => __('contact.mobile_number_required'),
            'email.required' => __('contact.email_required'),
            'email.email' => __('contact.email_valid'),
            'email.unique' => __('contact.email_taken'),
            'address.required' => __('contact.address_required'),
            'country.required' => __('contact.country_required'),
            'state.required' => __('contact.state_required'),
            'city.required' => __('contact.city_required'),
        ];
    }
}
