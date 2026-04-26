<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Resource;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Resource\Resource;
use Illuminate\Validation\Rule;

class UpdateResourceRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = implode(',', Resource::getTypes());

        return [
            'resource_type' => "sometimes|required|string|in:{$types}",
            'title' => 'sometimes|required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'status' => 'sometimes|required|boolean',
            'icon' => 'nullable|string|max:255',
            'is_partner' => 'nullable|boolean',
            'is_borrower' => 'nullable|boolean',
            'file' => 'nullable|string|regex:/\.pdf$/i',
            'link' => 'nullable|url|max:255',
            'overview' => 'nullable|array',
            'overview.*' => 'nullable|string',
            'impact' => 'nullable|array',
            'impact.*' => 'nullable|string',
            'providers' => 'nullable|array',
            'providers.*.name' => 'nullable|string|max:255',
            'providers.*.desc' => 'nullable|string',
            'providers.*.is_recommended' => 'nullable|boolean',
            'providers.*.btn_label' => 'nullable|string|max:255',
            'providers.*.link' => 'nullable|string|max:500',
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
            'is_partner' => __('resource.attributes.is_partner'),
            'is_borrower' => __('resource.attributes.is_borrower'),
            'overview' => __('resource.attributes.overview'),
            'impact' => __('resource.attributes.impact'),
            'providers' => __('resource.attributes.providers'),
            'providers.*.name' => __('resource.attributes.provider_name'),
            'providers.*.desc' => __('resource.attributes.provider_desc'),
            'providers.*.btn_label' => __('resource.attributes.provider_btn_label'),
            'providers.*.link' => __('resource.attributes.provider_link'),
        ];
    }

    public function messages(): array
    {
        return [
            'resource_type.required' => __('resource.validation.resource_type_required'),
            'resource_type.in' => __('resource.validation.resource_type_invalid'),
            'title.required' => __('resource.validation.title_required'),
            'file.regex' => __('resource.validation.file_pdf'),
            'link.url' => __('resource.validation.link_invalid'),
            'is_borrower.boolean' => __('resource.validation.is_borrower_boolean'),
            'is_partner.boolean' => __('resource.validation.is_partner_boolean'),
        ];
    }
}
