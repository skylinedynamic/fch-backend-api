<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\UsPhoneNumber;

class SendTestSmsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/', new UsPhoneNumber()],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => __('sms_config.test_phone_required'),
            'phone.regex' => __('sms_config.test_phone_regex'),
        ];
    }
}
