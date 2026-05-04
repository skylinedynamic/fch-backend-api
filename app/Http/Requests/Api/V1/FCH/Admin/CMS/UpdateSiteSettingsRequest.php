<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CMS;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateSiteSettingsRequest extends BaseApiRequest
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
            'site_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500', 'regex:/^[^<>]*$/'],
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'email' => ['required', 'email', 'max:255'],
            'map_iframe' => ['required', 'string', 'max:2000'],
            'site_slogan' => ['nullable', 'string', 'max:255'],
            'header_logo' => ['nullable', 'sometimes'],
            'footer_logo' => ['nullable', 'sometimes'],
            'partner_logo' => ['nullable', 'sometimes'],
            'favicon' => ['nullable', 'sometimes'],
            'social_links' => ['nullable'],
            'google_measurement_id' => ['nullable', 'string', 'max:255'],
            'recaptcha_site_key' => ['nullable', 'string', 'max:255'],
            'recaptcha_secret_key' => ['nullable', 'string', 'max:255'],
            'agreement_signing_enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'site_name.required' => __('site_settings.site_name_required'),
            'address.required' => __('site_settings.address_required'),
            'phone_number.required' => __('site_settings.phone_required'),
            'email.required' => __('site_settings.email_required'),
            'email.email' => __('site_settings.email_email'),
            'map_iframe.required' => __('site_settings.map_iframe_required'),
        ];
    }
}
