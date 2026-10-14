<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateFundingGroupRequest extends BaseApiRequest
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
        $id = $this->route('id') ?? $this->route('funding_group');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('funding_groups', 'name')->ignore($id)->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('funding_sequencing.group_name_required'),
            'name.string'   => __('funding_sequencing.group_name_string'),
            'name.max'      => __('funding_sequencing.group_name_max'),
            'name.unique'   => __('funding_sequencing.group_name_unique'),
        ];
    }
}
