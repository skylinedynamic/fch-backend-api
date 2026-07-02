<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Lender;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Lender\Lender;
use Illuminate\Validation\Rule;

class UpdateLenderRequest extends BaseApiRequest
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
        $id = $this->route('id');
        return [
            'name' => 'required|string|max:255',
            'legal_name' => 'required|string|max:255',
            'product_categories' => 'required|array',
            'product_categories.*' => 'string',
            'contact_name' => 'required|string|max:255',
            'contact_title' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => ['required', 'string', 'max:20', Rule::unique('lenders', 'contact_phone')->ignore($id)],
            'website' => 'nullable|max:255',
            'submission_method' => 'nullable|string|max:255',
            'submission_email' => 'nullable|email|max:255',
            'submission_portal_link' => 'nullable|max:255',
            'iso_status' => 'nullable|string|max:255',
            'status' => 'required|string|in:' . Lender::STATUS_ACTIVE . ',' . Lender::STATUS_INACTIVE,
            'internal_notes' => 'nullable|string',
            'last_verified_date' => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'product_categories.required' => __('lender.product_categories_required'),
            'name.required' => __('lender.name_required'),
            'name.string' => __('lender.name_string'),
            'name.max' => __('lender.name_max'),
            'legal_name.required' => __('lender.legal_name_required'),
            'contact_name.required' => __('lender.contact_name_required'),
            'contact_title.required' => __('lender.contact_title_required'),
            'contact_phone.required' => __('lender.contact_phone_required'),
            'contact_email.required' => __('lender.contact_email_required'),
            'contact_email.email' => __('lender.contact_email_email'),
            'contact_phone.max' => __('lender.contact_phone_max'),
            'contact_phone.unique' => __('lender.contact_phone_unique'),
            'website.max' => __('lender.website_max'),
            'submission_email.email' => __('lender.submission_email_email'),
            'submission_portal_link.max' => __('lender.submission_portal_link_max'),
            'status.required' => __('lender.status_required'),
            'status.in' => __('lender.status_in'),
            'last_verified_date.required' => __('lender.last_verified_date_required'),
            'last_verified_date.date' => __('lender.last_verified_date_date'),
        ];
    }
}
