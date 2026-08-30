<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;

class DeleteItemsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paths' => ['required', 'array'],
            'paths.*' => ['required', 'string'],
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'paths.required' => __('filemanager.paths_required'),
            'paths.*.required' => __('filemanager.paths_required'),
            'entity_type.required' => __('filemanager.entity_type_required'),
            'entity_id.required' => __('filemanager.entity_id_required'),
        ];
    }
}
