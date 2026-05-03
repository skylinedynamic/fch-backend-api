<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CMS;

use App\Http\Requests\Api\V1\BaseApiRequest;

class SiteSettingRequest extends BaseApiRequest
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
    protected function prepareForValidation(): void
    {
        if (is_string($this->social_links)) {
            $decoded = json_decode($this->social_links, true);
            if (is_array($decoded)) {
                $cleaned = array_map(function($link) {
                    return [
                        'platform' => (isset($link['platform']) && $link['platform'] !== '') ? trim($link['platform']) : null,
                        'url' => (isset($link['url']) && $link['url'] !== '') ? trim($link['url']) : null,
                        'icon' => (isset($link['icon']) && $link['icon'] !== '') ? trim($link['icon']) : null,
                    ];
                }, $decoded);

                // Filter out completely empty rows so they don't trigger validation
                $cleaned = array_values(array_filter($cleaned, function($link) {
                    return !is_null($link['platform']) || !is_null($link['url']);
                }));

                $this->merge([
                    'social_links' => $cleaned,
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500', 'regex:/^[^<>]*$/'],
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'email' => ['required', 'email', 'max:255'],
            'map_iframe' => ['required', 'string', 'max:2000', 'regex:/<iframe.*src="https:\/\/www\.google\.com\/maps\/embed\?.*".*><\/iframe>/i'],
            'site_slogan' => ['nullable', 'string', 'max:255'],
            'header_logo' => [
                'nullable', 
                'sometimes', 
                request()->hasFile('header_logo') ? 'image' : 'string',
                request()->hasFile('header_logo') ? 'mimes:png,jpg,jpeg,svg,webp' : 'nullable',
                'max:2048'
            ],
            'footer_logo' => [
                'nullable', 
                'sometimes', 
                request()->hasFile('footer_logo') ? 'image' : 'string',
                request()->hasFile('footer_logo') ? 'mimes:png,jpg,jpeg,svg,webp' : 'nullable',
                'max:2048'
            ],
            'partner_logo' => [
                'nullable', 
                'sometimes', 
                request()->hasFile('partner_logo') ? 'image' : 'string',
                request()->hasFile('partner_logo') ? 'mimes:png,jpg,jpeg,svg,webp' : 'nullable',
                'max:2048'
            ],
            'favicon' => [
                'nullable', 
                'sometimes', 
                request()->hasFile('favicon') ? 'image' : 'string',
                request()->hasFile('favicon') ? 'mimes:png,jpg,jpeg,svg,webp,ico' : 'nullable',
                'max:1024'
            ],
            'social_links' => ['nullable', 'array'],
            'social_links.*.platform' => ['required_with:social_links.*.url', 'nullable', 'string', 'max:100'],
            'social_links.*.url' => ['required_with:social_links.*.platform', 'nullable', 'url', 'max:255'],
            'social_links.*.icon' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function attributes(): array
    {
        return [
            'social_links.*.platform' => 'social platform',
            'social_links.*.url' => 'social URL',
        ];
    }

    public function messages(): array
    {
        return [
            'site_name.required' => __('site_settings.site_name_required'),
            'site_name.max' => __('site_settings.site_name_max'),
            'address.required' => __('site_settings.address_required'),
            'address.max' => __('site_settings.address_max'),
            'address.regex' => __('site_settings.address_invalid'),
            'phone_number.required' => __('site_settings.phone_required'),
            'phone_number.regex' => 'The phone number format is invalid. Please use (XXX) XXX-XXXX format.',
            'email.required' => __('site_settings.email_required'),
            'email.email' => __('site_settings.email_email'),
            'email.max' => __('site_settings.email_max'),
            'map_iframe.required' => __('site_settings.map_iframe_required'),
            'map_iframe.max' => __('site_settings.map_iframe_max'),
            'site_slogan.max' => __('site_settings.site_slogan_max'),
            'map_iframe.regex'    => __('site_settings.map_iframe_invalid'),
            'header_logo.image' => __('site_settings.logo_image'),
            'header_logo.mimes' => __('site_settings.logo_mimes'),
            'header_logo.max' => __('site_settings.logo_max'),
            'footer_logo.image' => __('site_settings.logo_image'),
            'footer_logo.mimes' => __('site_settings.logo_mimes'),
            'footer_logo.max' => __('site_settings.logo_max'),
            'partner_logo.image' => __('site_settings.logo_image'),
            'partner_logo.mimes' => __('site_settings.logo_mimes'),
            'partner_logo.max' => __('site_settings.logo_max'),
            'favicon.image' => __('site_settings.favicon_image'),
            'favicon.mimes' => __('site_settings.favicon_mimes'),
            'favicon.max' => __('site_settings.favicon_max'),
            'social_links.*.platform.required_with' => 'The social platform is required when URL is present.',
            'social_links.*.url.required_with' => 'The social URL is required when platform is present.',
            'social_links.*.url.url' => 'The social URL must be a valid URL.',
        ];
    }
}
