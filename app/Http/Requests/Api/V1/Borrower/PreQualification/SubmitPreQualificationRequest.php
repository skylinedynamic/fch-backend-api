<?php

namespace App\Http\Requests\Api\V1\Borrower\PreQualification;

use App\Http\Requests\Api\V1\BaseApiRequest;

class SubmitPreQualificationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable','integer','exists:pre_qualification_requests,id'],
            
        ];
    }


    public function messages(): array
    {
        return [
            'id.integer' => __('borrower_validation.id_integer'),
            'id.exists' => __('borrower_validation.id_exists')
        ];
    }
}