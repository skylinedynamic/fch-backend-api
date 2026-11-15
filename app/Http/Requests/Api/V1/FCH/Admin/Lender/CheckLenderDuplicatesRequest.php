<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Lender;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * Add/Edit Lender: which lenders already have this name or legal name, so the
 * form can warn. Either field may come alone; the lender being edited is left out.
 */
class CheckLenderDuplicatesRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'ignore_id' => ['nullable', 'integer'],
        ];
    }
}
