<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\SafeUpload;
use App\Support\Files\FileManagerFolders;
use App\Support\Files\Folder;

class UploadFileRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A public folder is served from /storage without signing in: pictures
        // only (an SVG is stripped of its scripts). Documents go to a private one.
        $folder = (string) $this->input('entity_type');
        $type = FileManagerFolders::isPublic($folder) ? SafeUpload::image() : SafeUpload::document();

        $rules = [
            'file' => ['required', 'file', $type],
            'path' => ['required', 'string'],
            'entity_type' => ['required', 'string'],
        ];

        if ($folder === Folder::CREDIT_ACCOUNTS) {
            $rules['entity_id'] = ['nullable'];
        } else {
            $rules['entity_id'] = ['required', 'integer'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'file.required' => __('filemanager.file_required'),
            'path.required' => __('filemanager.path_required'),
            'entity_type.required' => __('filemanager.entity_type_required'),
            'entity_id.required' => __('filemanager.entity_id_required'),
        ];
    }
}
