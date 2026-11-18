<?php

namespace App\Http\Requests\Api\V1\FCH\Common\RolePermission;

use Illuminate\Validation\Rule;
use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\NotReservedRoleName;
use Illuminate\Support\Facades\Auth;

class UpdateRoleRequest extends BaseApiRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $partnerId = Auth::user()->partner_id;
        $roleId = $this->route('role');

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                new NotReservedRoleName(),
                Rule::unique('roles')
                    ->where(fn($query) => $query->where('partner_id', $partnerId))
                    ->ignore($roleId), 
            ],

            'description' => 'nullable|string|max:255',
            'permission_ids' => 'required|array|exists:permissions,id',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __('role_permission.name_required'),
            'name.string' => __('role_permission.name_string'),
            'name.max' => __('role_permission.name_max'),
            'name.unique' => __('role_permission.name_unique'),
            'description.required' => __('role_permission.description_required'),
            'description.string' => __('role_permission.description_string'),
            'description.max' => __('role_permission.description_max'),
            'permission_ids.required' => __('role_permission.permission_ids_required'),
            'permission_ids.array' => __('role_permission.permission_ids_array'),
        ];
    }

}
