<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;

class ListDocumentsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['nullable', 'string'],
        ];  
    }

    public function messages(): array
    {
        return [
            'path.required' => __('filemanager.path_required'),
            ];
    }
}
