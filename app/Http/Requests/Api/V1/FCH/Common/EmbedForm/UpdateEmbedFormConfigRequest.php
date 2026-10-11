<?php

namespace App\Http\Requests\Api\V1\FCH\Common\EmbedForm;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * PUT /common/embed-forms/config
 *
 * Every field is optional: the configuration screen saves the whole form, but a
 * caller updating one setting should not have to resend the rest.
 */
class UpdateEmbedFormConfigRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'form_title' => ['nullable', 'string', 'max:255'],

            // Full URLs. The submit endpoint compares only the host, so a path
            // or scheme here is harmless - but requiring a URL keeps the entries
            // unambiguous on the screen that lists them back.
            'allowed_domains' => ['nullable', 'array'],
            'allowed_domains.*' => ['string', 'url'],

            'notification_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
