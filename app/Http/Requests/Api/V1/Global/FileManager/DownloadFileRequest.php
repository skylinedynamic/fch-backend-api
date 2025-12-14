<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;

class DownloadFileRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string'],
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => __('filemanager.path_required'),
            'entity_type.required' => __('filemanager.entity_type_required'),
            'entity_id.required' => __('filemanager.entity_id_required'),
        ];
    }
}
