<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;

class CreateFolderRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'path' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('filemanager.name_required'),
            'path.required' => __('filemanager.path_required'),
        ];
    }
}
