<?php

namespace App\Http\Requests\Api\V1\FCH\Common\RolePermission;

use App\Http\Requests\Api\V1\BaseApiRequest;

class PermissionRequest extends BaseApiRequest
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
            'is_groupby' => 'nullable|boolean',
        ];
    }
}
