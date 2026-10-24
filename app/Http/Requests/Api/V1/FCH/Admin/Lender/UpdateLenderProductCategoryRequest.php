<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Lender;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateLenderProductCategoryRequest extends BaseApiRequest
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
        $id = $this->route('id');

        return [
            // Among categories that are not deleted - a deleted name is free again (O32).
            'name' => ['required', 'string', 'max:255', Rule::unique('lender_product_categories', 'name')->ignore($id)->withoutTrashed()],
            'status' => 'nullable|string|in:Active,Inactive',
            'sort_order' => 'nullable|integer'
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('lender.name_required'),
            'name.string' => __('lender.name_string'),
            'name.max' => __('lender.name_max'),
            'name.unique' => __('lender.category_name_unique'),
            'status.in' => __('lender.status_in'),
        ];
    }
}
