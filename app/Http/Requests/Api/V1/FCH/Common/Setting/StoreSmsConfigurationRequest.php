<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;

/**
 * Shape only. Whether an enabled configuration is complete - which depends on
 * the token already stored - is checked by SmsConfigurationService::save().
 */
class StoreSmsConfigurationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $countries = config('sms.countries', []);
        $countryCode = (string) $this->input('country');
        $country = $countries[$countryCode] ?? null;
        $numberPattern = $country ? PhoneNumber::e164Pattern($countryCode) : null;

        return [
            'country' => ['required', 'string', Rule::in(array_keys($countries))],
            'provider' => ['required', 'string', Rule::in($country['providers'] ?? [])],
            'is_enabled' => ['required', 'boolean'],
            'from_number' => array_filter(['nullable', 'string', $numberPattern ? 'regex:' . $numberPattern : null]),
            'account_sid' => ['nullable', 'string', 'regex:/^AC[0-9a-zA-Z]{32}$/'],
            'auth_token' => ['nullable', 'string', 'max:255'],
            'messaging_service_sid' => ['nullable', 'string', 'regex:/^MG[0-9a-zA-Z]{32}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'country.required' => __('sms_config.country_required'),
            'country.in' => __('sms_config.country_in'),
            'provider.required' => __('sms_config.provider_required'),
            'provider.in' => __('sms_config.provider_in'),
            'is_enabled.required' => __('sms_config.is_enabled_required'),
            'from_number.regex' => __('sms_config.from_number_regex'),
            'account_sid.regex' => __('sms_config.account_sid_regex'),
            'auth_token.max' => __('sms_config.auth_token_max'),
            'messaging_service_sid.regex' => __('sms_config.messaging_service_sid_regex'),
        ];
    }
}
