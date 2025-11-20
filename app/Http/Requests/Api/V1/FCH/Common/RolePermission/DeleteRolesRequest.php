<?php

namespace App\Http\Requests\Api\V1\FCH\Common\RolePermission;

use App\Http\Requests\Api\V1\BaseApiRequest;

class DeleteRolesRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:roles,id',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => __('role_permission.ids_required'),
            'ids.array' => __('role_permission.ids_array'),
            'ids.*.integer' => __('role_permission.ids_integer'),
            'ids.*.exists' => __('role_permission.ids_exist'),
        ];
    }

}