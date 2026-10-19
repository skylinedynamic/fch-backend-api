<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Setting\MailConfiguration;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One mail provider's settings. The fields follow the chosen provider (see
 * config/mail_configuration.php). A secret may be left blank or masked to keep
 * the stored one; MailConfigurationService insists on it when none is stored.
 */
class SaveMailConfigurationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $providers = MailConfiguration::providers();

        $rules = [
            'provider' => ['required', 'string', Rule::in(array_keys($providers))],
            'from_address' => ['required', 'email', 'max:255'],
            'bcc_emails' => ['nullable', 'string', 'max:2000'],
        ];

        foreach ($providers[$this->input('provider')]['fields'] ?? [] as $key => $field) {
            $presence = !empty($field['required']) && empty($field['secret']) ? 'required' : 'nullable';
            $rules[$key] = array_merge([$presence], $field['rules'] ?? []);
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $bcc = trim((string) $this->input('bcc_emails', ''));
            if ($bcc === '') {
                return;
            }

            foreach (array_filter(array_map('trim', explode(',', $bcc))) as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validator->errors()->add('bcc_emails', __('mail_config.bcc_emails_invalid'));

                    return;
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.required' => __('mail_config.provider_invalid'),
            'provider.in' => __('mail_config.provider_invalid'),
            'from_address.required' => __('mail_config.from_address_required'),
            'from_address.email' => __('mail_config.from_address_email'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect(MailConfiguration::providers()[$this->input('provider')]['fields'] ?? [])
            ->map(fn (array $field) => $field['label'])
            ->all();
    }
}
