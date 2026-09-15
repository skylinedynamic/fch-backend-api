<?php

namespace App\Http\Requests\Api\V1\FCH\Common\EmbedForm;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\EmbedForm\EmbedFormSubmission;
use Illuminate\Validation\Rule;

/**
 * PUT /common/embed-forms/submissions/{id}/status
 *
 * The allowed values come from the model rather than a literal list, so a status
 * added there cannot be silently rejected here.
 */
class UpdateEmbedFormSubmissionStatusRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(EmbedFormSubmission::getStatuses())],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => __('embed_form.status_invalid'),
        ];
    }
}
