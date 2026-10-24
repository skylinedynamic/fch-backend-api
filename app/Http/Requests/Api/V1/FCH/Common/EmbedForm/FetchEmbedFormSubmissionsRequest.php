<?php

namespace App\Http\Requests\Api\V1\FCH\Common\EmbedForm;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\EmbedForm\EmbedFormSubmission;
use Illuminate\Validation\Rule;

/**
 * POST /common/embed-forms/submissions/fetch
 *
 * Listing filters. These reached the query unvalidated before, so a malformed
 * date or per_page went to the database as-is.
 */
class FetchEmbedFormSubmissionsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(EmbedFormSubmission::getStatuses())],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort_field' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],

            // Host only, enforced in the controller - a partner sending this
            // still gets scoped to their own submissions.
            'partner_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => __('embed_form.status_invalid'),
            'date_to.after_or_equal' => __('embed_form.date_range_invalid'),
        ];
    }
}
