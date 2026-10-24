<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Lender;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

/** A selection of lenders to delete, or to check before deleting. */
class DeleteLendersRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', Rule::exists('lenders', 'id')->whereNull('deleted_at')],
        ];
    }
}
