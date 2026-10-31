<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * The activity list's record filters must be ids. The service casts them to
 * int, so "abc" became 0 - which MySQL matches against every entry whose
 * borrower_id is JSON null - and the panel listed unrelated activity (BUG-001).
 */
class FetchActivityLogsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'borrower_id'            => ['nullable', 'integer', 'min:1'],
            'partner_id'             => ['nullable', 'integer', 'min:1'],
            'lead_id'                => ['nullable', 'integer', 'min:1'],
            'funding_request_id'     => ['nullable', 'integer', 'min:1'],
            'funding_application_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
