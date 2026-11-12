<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * The partner ids to archive or restore.
 *
 * Authorisation is the route's permission middleware (Permission::UpdatePartners); this
 * only has to establish that the ids are real, so an unknown id comes back as a
 * 422 naming the problem rather than as a silent no-op.
 */
class ArchivePartnerRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:partners,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one partner first.',
            'ids.*.exists' => 'One of the selected partners no longer exists.',
        ];
    }
}
