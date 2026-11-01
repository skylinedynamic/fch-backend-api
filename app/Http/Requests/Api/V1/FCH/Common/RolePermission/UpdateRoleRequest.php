<?php

namespace App\Http\Requests\Api\V1\FCH\Common\RolePermission;

use Illuminate\Validation\Rule;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;
use App\Models\Common\Role;

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
                Rule::unique('roles')
                    ->where(fn($query) => $query->where('partner_id', $partnerId))
                    ->ignore($roleId), 
            ],

            'description' => 'nullable|string|max:255',
            'permission_ids' => 'required|array|exists:permissions,id',
        ];
    }

    /**
     * A system role (`is_static`: Admin, Developer) keeps its name and its
     * permissions. The seeded Admin role was fully editable, so its
     * permissions could be unticked and the only admin could take away their
     * own access (R2 BUG-011). Its description may still change, and a save
     * that leaves the name and permissions as they are goes through.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $role = $this->route('role');
            $role = $role instanceof Role ? $role : Role::find($role);
            if (!$role || !$role->is_static) {
                return;
            }

            if (trim((string) $this->input('name')) !== $role->name) {
                $validator->errors()->add('name', __('role_permission.role_static_cannot_change'));
            }

            $sent = collect($this->input('permission_ids', []))->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            $held = $role->permissions()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($sent !== $held) {
                $validator->errors()->add('permission_ids', __('role_permission.role_static_cannot_change'));
            }
        }];
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
