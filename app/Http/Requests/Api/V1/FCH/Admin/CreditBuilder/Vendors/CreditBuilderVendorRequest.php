<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\Vendors;

use Illuminate\Foundation\Http\FormRequest;

class CreditBuilderVendorRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'requirements' => 'nullable|array',
            'benefits' => 'nullable|array',
            'report_to' => 'nullable|string|max:255',
            'tier_id' => 'nullable|exists:tiers,id',
            'status' => 'nullable|string|in:active,inactive',
        ];
    }
}
