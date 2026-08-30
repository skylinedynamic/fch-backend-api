<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;

class PasteItemsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array'],
            'files.*' => ['required', 'array'], // Assuming file objects have properties
            'files.*.path' => ['required', 'string'], // At least path is needed
            'destinationPath' => ['required', 'string'],
            'operation' => ['required', 'in:copy,move'],
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required_unless:entity_type,credit-accounts', 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => __('filemanager.files_required'),
            'files.*.required' => __('filemanager.files_required'),
            'destinationPath.required' => __('filemanager.destination_path_required'),
            'operation.required' => __('filemanager.operation_required'),
            'entity_type.required' => __('filemanager.entity_type_required'),
            'entity_id.required' => __('filemanager.entity_id_required'),
        ];
    }
}
