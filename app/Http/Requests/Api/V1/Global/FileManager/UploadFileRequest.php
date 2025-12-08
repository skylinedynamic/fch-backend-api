<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UploadFileRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'section' => ['required', 'string'],
            'path' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => __('filemanager.file_required'),
            'section.required' => __('filemanager.section_required'),
            'path.required' => __('filemanager.path_required'),
        ];
    }
}
