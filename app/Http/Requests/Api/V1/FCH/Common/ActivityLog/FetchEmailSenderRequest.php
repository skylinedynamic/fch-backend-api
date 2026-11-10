<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * New Email, for the host: which addresses it can go out from. `partner_id`
 * is the partner the record (the client) belongs to; none means a host record.
 */
class FetchEmailSenderRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
        ];
    }
}
