<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;

class RenameItemRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string'],
            'newName' => ['required', 'string'],
            'section' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => __('filemanager.path_required'),
            'newName.required' => __('filemanager.new_name_required'),
            'section.required' => __('filemanager.section_required'),
        ];
    }
}
