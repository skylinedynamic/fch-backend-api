<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use App\Helpers\ApiResponse;

class StorePartnerDocumentRequest extends FormRequest
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
            'share_link' => ['nullable'],
            'file_path' => ['nullable', 'file', 'max:20480'], // 20MB max
            'document_name' => ['nullable', 'string', 'max:255'],
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

     /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */

    protected function failedValidation(Validator $validator)
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$field] = $messages[0]; 
        }
        throw new HttpResponseException(
            ApiResponse::validationError($errors, __('auth.validation_failed'))
        );
    }
}
