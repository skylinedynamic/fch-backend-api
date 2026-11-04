<?php

namespace App\Http\Requests\Api\V1\FCH\Common\EmbedForm;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * POST /common/embed-forms/submissions/{id}/convert-to-borrower
 *
 * `confirm_existing_borrower` is sent on the second call, after the partner has
 * seen the existing borrower's details and agreed to attach the new funding
 * application to them.
 */
class ConvertEmbedFormSubmissionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'salesperson_id' => ['nullable', 'integer', 'exists:users,id'],
            'confirm_existing_borrower' => ['nullable', 'boolean'],
            // Converting onto an existing borrower: also fill its blank profile
            // fields from the submission. Only when the partner agreed (BUG-064).
            'fill_profile_fields' => ['nullable', 'boolean'],
        ];
    }
}
