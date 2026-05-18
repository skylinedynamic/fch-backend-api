<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateGeneralConfigurationsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:255'],
            'site_slogan' => ['nullable', 'string', 'max:255'],
            'copyright_text' => ['nullable', 'string', 'max:500'],
            'header_logo' => $this->hasFile('header_logo') ? ['image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'] : ['nullable', 'string'],
            'footer_logo' => $this->hasFile('footer_logo') ? ['image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'] : ['nullable', 'string'],
            'primary_logo' => $this->hasFile('primary_logo') ? ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'] : ['required', 'string'],
            'secondary_logo' => $this->hasFile('secondary_logo') ? ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'] : ['nullable', 'string'],
            'favicon' => $this->hasFile('favicon') ? ['required', 'image', 'mimes:png,jpg,jpeg,svg,ico,webp', 'max:2048'] : ['required', 'string'],
            'website_favicon' => $this->hasFile('website_favicon') ? ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,ico,webp', 'max:2048'] : ['nullable', 'string'],
            'partner_logo' => ['nullable', 'string'],
            'borrower_logo' => ['nullable', 'string'],
            'partner_favicon' => ['nullable', 'string'],
            'borrower_favicon' => ['nullable', 'string'],
            'primary_color' => ['required', 'string', 'max:20'],
            'secondary_color' => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        $imageMsg = __('app_config.image_validation_error');
        $favMsg = __('app_config.favicon_validation_error');

        return [
            'header_logo.image' => $imageMsg,
            'header_logo.mimes' => $imageMsg,
            'header_logo.max' => __('app_config.header_logo_max'),
            'footer_logo.image' => $imageMsg,
            'footer_logo.mimes' => $imageMsg,
            'footer_logo.max' => __('app_config.footer_logo_max'),
            'primary_logo.image' => $imageMsg,
            'primary_logo.mimes' => $imageMsg,
            'primary_logo.max' => __('app_config.primary_logo_max'),
            'secondary_logo.image' => $imageMsg,
            'secondary_logo.mimes' => $imageMsg,
            'secondary_logo.max' => __('app_config.secondary_logo_max'),
            'favicon.image' => $favMsg,
            'favicon.mimes' => $favMsg,
            'favicon.max' => __('app_config.favicon_max'),
            'website_favicon.image' => $favMsg,
            'website_favicon.mimes' => $favMsg,
            'website_favicon.max' => __('app_config.website_favicon_max'),
        ];
    }
}
