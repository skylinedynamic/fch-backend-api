<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Resource;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Resource;

class StoreResourceRequest extends FormRequest
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
            'subtitle' => 'required|string|max:255',
            'status' => 'required|boolean',
            'icon' => 'nullable|string|max:255',
            'file' => 'required_if:resource_type,legal_registration,download_assets,education_help|string|regex:/\.pdf$/i',
            'link' => 'required_if:resource_type,business_services,training_academy|nullable|url',
        ];
    }

    public function attributes(): array
    {
        return [
            'resource_type' => __('Resource Type'),
            'title' => __('Resource Title'),
            'subtitle' => __('Description Subtitle'),
            'status' => __('Status'),
            'icon' => __('Icon Class'),
            'file' => __('Upload File Asset'),
            'link' => __('Resource Link'),
        ];
    }

    public function messages(): array
    {
        return [
            'file.required_if' => __('The Upload File Asset is required for this resource type.'),
            'file.regex' => __('The Upload File Asset must be a PDF file.'),
            'link.required_if' => __('The Resource Link is required for this resource type.'),
        ];
    }
}
