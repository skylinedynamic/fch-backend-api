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
            'section' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'paths.required' => __('filemanager.paths_required'),
            'paths.*.required' => __('filemanager.paths_required'),
            'section.required' => __('filemanager.section_required'),
        ];
    }
}
