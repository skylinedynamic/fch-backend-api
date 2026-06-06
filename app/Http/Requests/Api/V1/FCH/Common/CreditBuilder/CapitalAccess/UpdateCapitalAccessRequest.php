<?php

namespace App\Http\Requests\Api\V1\FCH\Common\CreditBuilder\CapitalAccess;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateCapitalAccessRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'                 => 'sometimes|required|string|max:255',
            'description'          => 'nullable|string',
            'status'               => 'nullable|string|in:Active,Inactive',
            'credit_account_ids'   => 'nullable|array',
            'credit_account_ids.*' => 'integer|exists:credit_accounts,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'               => 'The capital access name is required.',
            'name.max'                    => 'The name may not be greater than 255 characters.',
            'credit_account_ids.array'    => 'Credit account IDs must be an array.',
            'credit_account_ids.*.exists' => 'One or more selected credit accounts are invalid.',
        ];
    }
}
