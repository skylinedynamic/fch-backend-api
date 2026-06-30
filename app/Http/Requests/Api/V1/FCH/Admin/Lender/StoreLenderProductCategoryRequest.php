<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Lender;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreLenderProductCategoryRequest extends BaseApiRequest
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
            'name' => 'required|string|max:255|unique:lender_product_categories,name',
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
            'name.unique' => __('lender.category_name_unique', ['default' => 'The category name has already been taken.']),
            'status.in' => __('lender.status_in'),
        ];
    }
}
