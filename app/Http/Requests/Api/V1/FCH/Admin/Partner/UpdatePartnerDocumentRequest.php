<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdatePartnerDocumentRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document_name' => ['nullable', 'string', 'max:255'],
            'file_path' => ['nullable', 'file', 'max:51200'], // 50MB max
            // support replacing with files array (uploaded files or path strings)
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable'],
            'document_type' => ['nullable', 'string', 'max:255']
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file_path.max' => __('partner.validation.document_file.max'),
        ];
    }
}
