<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Calendly;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * A partner's own Calendly OAuth app keys (Settings > Calendly). A blank
 * secret or signing key keeps the saved one; partner_id is for the host
 * account setting them up for a partner (checked by the service).
 */
class StoreCalendlySettingsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id' => 'nullable|integer',
            'client_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'client_secret' => 'nullable|string|max:500',
            'webhook_signing_key' => 'nullable|string|max:500',
        ];
    }
}
