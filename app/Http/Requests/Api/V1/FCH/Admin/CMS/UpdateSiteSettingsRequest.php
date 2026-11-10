<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CMS;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\EmbedFormSnippet;
use App\Rules\SafeUpload;
use App\Rules\UsPhoneNumber;
use App\Support\EmbedForm\EmbedSnippet;

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
            'entity_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', 'string'],
            'site_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500', 'regex:/^[^<>]*$/'],
            'phone_number' => ['required', 'string', new UsPhoneNumber()],
            'email' => ['required', 'email', 'max:255'],
            'map_iframe' => ['required', 'string', 'max:2000'],
            // Embed code copied from Embed Form Settings for the /apply page.
            // Parsed, never served as HTML (EmbedSnippet).
            EmbedSnippet::SETTING_KEY => ['nullable', 'string', 'max:3000', new EmbedFormSnippet],
            'site_slogan' => ['nullable', 'string', 'max:255'],
            'header_logo' => ['nullable', 'sometimes', SafeUpload::image()],
            'footer_logo' => ['nullable', 'sometimes', SafeUpload::image()],
            'primary_logo' => ['nullable', 'sometimes', SafeUpload::image()],
            'secondary_logo' => ['nullable', 'sometimes', SafeUpload::image()],
            'favicon' => ['nullable', 'sometimes', SafeUpload::image()],
            'website_favicon' => ['nullable', 'sometimes', SafeUpload::image()],
            'partner_logo' => ['nullable', 'sometimes', SafeUpload::image()],
            'borrower_logo' => ['nullable', 'sometimes', SafeUpload::image()],
            'partner_favicon' => ['nullable', 'sometimes', SafeUpload::image()],
            'borrower_favicon' => ['nullable', 'sometimes', SafeUpload::image()],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'secondary_color' => ['nullable', 'string', 'max:20'],
            'social_links' => ['nullable'],
            'google_measurement_id' => ['nullable', 'string', 'max:255'],
            'recaptcha_site_key' => ['nullable', 'string', 'max:255'],
            'recaptcha_secret_key' => ['nullable', 'string', 'max:255'],
            // A blank secret means "keep"; this deletes the saved one.
            'remove_recaptcha_secret_key' => ['sometimes', 'boolean'],
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
            'partner_favicon.image' => __('site_settings.partner_favicon_image'),
            'borrower_favicon.image' => __('site_settings.borrower_favicon_image'),
        ];
    }
}
