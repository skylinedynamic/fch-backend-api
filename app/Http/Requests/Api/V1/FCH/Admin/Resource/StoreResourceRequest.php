<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Resource;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Resource\Resource;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = implode(',', Resource::getTypes());

        return [
            'resource_type' => "required|string|in:{$types}",
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'status' => 'required|boolean',
            'icon' => 'nullable|string|max:255',
            'file' => Rule::when(
                in_array($this->resource_type, ['legal_registration', 'download_assets', 'education_help']),
                ['required', 'string', 'regex:/\.pdf$/i'],
                ['nullable']
            ),
            'link' => Rule::when(
                in_array($this->resource_type, ['business_services', 'training_academy']),
                ['required', 'url', 'max:255'],
                ['nullable']
            ),
        ];
    }

    public function attributes(): array
    {
        return [
            'resource_type' => __('resource.attributes.resource_type'),
            'title' => __('resource.attributes.title'),
            'subtitle' => __('resource.attributes.subtitle'),
            'status' => __('resource.attributes.status'),
            'icon' => __('resource.attributes.icon'),
            'file' => __('resource.attributes.file'),
            'link' => __('resource.attributes.link'),
        ];
    }

    public function messages(): array
    {
        return [
            'resource_type.required' => __('resource.validation.resource_type_required'),
            'resource_type.in' => __('resource.validation.resource_type_invalid'),
            'title.required' => __('resource.validation.title_required'),
            'file.required_if' => __('resource.validation.file_required'),
            'file.regex' => __('resource.validation.file_pdf'),
            'link.required_if' => __('resource.validation.link_required'),
            'link.url' => __('resource.validation.link_invalid'),
        ];
    }
}
