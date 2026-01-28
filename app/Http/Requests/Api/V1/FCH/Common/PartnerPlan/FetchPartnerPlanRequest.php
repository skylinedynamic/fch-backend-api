<?php

namespace App\Http\Requests\Api\V1\FCH\Common\PartnerPlan;

use Illuminate\Foundation\Http\FormRequest;

class FetchPartnerPlanRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ];
    }
}
