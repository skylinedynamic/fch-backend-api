<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\PartnerNotificationRecipient;

/**
 * Partner profile › Mail Notification: `recipients` maps a notification type
 * (PartnerNotificationRecipient::TYPES) to the address its emails go to, or
 * null / empty to send them to the partner's own email again. A type left out
 * keeps its recipient.
 */
class UpdatePartnerMailNotificationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            // Only the known types: an unknown key is refused under `recipients`.
            'recipients' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, \Closure $fail) {
                $unknown = array_diff(array_keys((array) $value), PartnerNotificationRecipient::TYPES);
                if ($unknown !== []) {
                    $fail(__('partner.mail_notification.unknown_type', ['types' => implode(', ', $unknown)]));
                }
            }],
        ];

        foreach (PartnerNotificationRecipient::TYPES as $type) {
            $rules["recipients.{$type}"] = ['nullable', 'string', 'email:rfc', 'max:255'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach (PartnerNotificationRecipient::TYPES as $type) {
            $attributes["recipients.{$type}"] = __("partner.mail_notification.types.{$type}.label");
        }

        return $attributes;
    }
}
