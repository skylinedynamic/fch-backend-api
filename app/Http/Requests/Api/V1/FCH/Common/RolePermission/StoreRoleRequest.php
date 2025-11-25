<?php

namespace App\Http\Requests\Api\V1\FCH\Common\RolePermission;

use App\Helpers\ApiResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreRoleRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

  
    public function rules(): array
    {
        $partnerId = Auth::user()->partner_id;

        return [
            'name' => [
                'required',
                'string',
                'max:20',
                Rule::unique('roles')->where(function ($query) use ($partnerId) {
                    return $query->where('partner_id', $partnerId);
                }),
            ],
            'description' => 'nullable|string|max:255',
            'permission_ids' => ['required', 'array', 'exists:permissions,id'],
        ];
    }

    /**
     * Get the custom error messages for validation failures.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('role_permission.name_required'),
            'name.string' => __('role_permission.name_string'),
            'name.max' => __('role_permission.name_max'),
            'name.unique' => __('role_permission.name_unique', ['name' => $this->name]),
            'description.required' => __('role_permission.description_required'),
            'description.string' => __('role_permission.description_string'),
            'description.max' => __('role_permission.description_max'),
            'permission_ids.required' => __('role_permission.permission_ids_required'),
            'permission_ids.array' => __('role_permission.permission_ids_array'),
        ];
    }

}
