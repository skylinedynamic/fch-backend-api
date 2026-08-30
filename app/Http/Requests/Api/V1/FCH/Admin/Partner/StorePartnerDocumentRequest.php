<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\BaseApiRequest;

class StorePartnerDocumentRequest extends BaseApiRequest
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
            'partner_id' => ['required', 'integer', 'exists:partners,id'],
            'file_path' => ['nullable'],
            'file_path.*' => ['nullable'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'partner_id.required' => __('partner.validation.partner_id.required'),
            'partner_id.exists' => __('partner.validation.partner_id.exists'),
            'file_path.max' => __('partner.validation.document_file.max'),
        ];
    }
}
