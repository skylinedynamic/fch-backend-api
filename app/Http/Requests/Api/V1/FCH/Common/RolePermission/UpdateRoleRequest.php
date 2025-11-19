<?php

namespace App\Http\Requests\Api\V1\FCH\Common\RolePermission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;

class UpdateRoleRequest extends FormRequest
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
                'max:255',
                Rule::unique('roles')
                    ->where(fn($query) => $query->where('partner_id', $partnerId))
                    ->ignore($roleId), 
            ],

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
            'permission_ids.required' => __('role_permission.permission_ids_required'),
            'permission_ids.array' => __('role_permission.permission_ids_array'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$field] = $messages[0];
        }

        throw new HttpResponseException(
            ApiResponse::validationError($errors, __('auth.validation_failed'))
        );
    }
}
