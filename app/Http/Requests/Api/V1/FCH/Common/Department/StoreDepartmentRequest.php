<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Department;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Department\Department;
use Closure;
use Illuminate\Support\Facades\Auth;

class StoreDepartmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                // One department per name within the partner, ignoring case (BUG-182).
                function (string $attribute, mixed $value, Closure $fail) {
                    $exists = Department::where('partner_id', Auth::user()->partner_id)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail(__('employee.department_exists'));
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('employee.department_name_required'),
            'name.max' => __('employee.department_name_max'),
        ];
    }
}
