<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreEmailConfigurationRequest extends BaseApiRequest
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
        $rules = [
            'id'                          => 'nullable|integer',
            'partner_id'                  => 'nullable|integer|exists:partners,id',
            'enable_custom_configuration' => 'required|boolean',
            'is_active'                   => 'nullable|boolean',
        ];

        // Only validate provider-specific fields if custom configuration is enabled
        if ($this->input('enable_custom_configuration')) {
            $rules = array_merge($rules, [
                'email_provider'  => 'required|string|in:smtp,aws_ses',
                'from_address'    => 'required|email',
                // 'from_name'       => 'required|string|max:255',
                'site_name'       => 'nullable|string|max:255',
                'bcc_emails'      => 'nullable|string',
                
                // SMTP Settings
                'smtp_host'       => 'required_if:email_provider,smtp|nullable|string',
                'smtp_port'       => 'required_if:email_provider,smtp|nullable|integer|between:1,65535',
                'smtp_encryption' => 'required_if:email_provider,smtp|nullable|string|in:tls,ssl,none',
                'smtp_username'   => 'required_if:email_provider,smtp|nullable|string',
                'smtp_password'   => 'required_if:email_provider,smtp|nullable|string',
                
                // AWS Settings
                'aws_access_key'  => 'required_if:email_provider,aws_ses|nullable|string',
                'aws_secret_key'  => 'required_if:email_provider,aws_ses|nullable|string',
                'aws_region'      => 'required_if:email_provider,aws_ses|nullable|string',
            ]);
        }

        return $rules;
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email_provider.required'     => __('email_config.provider_required'),
            'from_address.required'       => __('email_config.from_address_required'),
            'from_address.email'          => __('email_config.from_address_email'),
            // 'from_name.required'          => __('email_config.from_name_required'),
            'smtp_host.required_if'       => __('email_config.smtp_host_required_if'),
            'smtp_port.required_if'       => __('email_config.smtp_port_required_if'),
            'smtp_port.integer'           => __('email_config.smtp_port_integer'),
            'smtp_port.between'           => __('email_config.smtp_port_between'),
            'smtp_encryption.required_if' => __('email_config.smtp_encryption_required_if'),
            'smtp_username.required_if'   => __('email_config.smtp_username_required_if'),
            'smtp_password.required_if'   => __('email_config.smtp_password_required_if'),
            'aws_access_key.required_if' => __('email_config.aws_access_key_required_if'),
            'aws_secret_key.required_if' => __('email_config.aws_secret_key_required_if'),
            'aws_region.required_if'     => __('email_config.aws_region_required_if'),
        ];
    }
}
