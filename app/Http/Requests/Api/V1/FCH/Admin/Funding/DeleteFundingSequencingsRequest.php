<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

/** A selection of funding sequences to delete, or to check before deleting. */
class DeleteFundingSequencingsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', Rule::exists('funding_sequencings', 'id')->whereNull('deleted_at')],
        ];
    }
}
