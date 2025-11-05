<?php

namespace App\Http\Requests\Api\V1\Borrower\PreQualification;

use App\Models\Borrower\PreQualificationRequest as BorrowerPreQualificationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreQualificationRequest extends FormRequest
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
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => ['nullable','string',Rule::in(BorrowerPreQualificationRequest::STATUS)],
            'query' => 'nullable|string',
        ];
    }
}
