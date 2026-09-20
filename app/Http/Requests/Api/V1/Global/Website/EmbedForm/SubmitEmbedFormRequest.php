<?php

namespace App\Http\Requests\Api\V1\Global\Website\EmbedForm;

use App\Http\Requests\Api\V1\BaseApiRequest;

class SubmitEmbedFormRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Personal Information
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'birthday' => ['nullable', 'date'],
            'ssn' => ['nullable', 'string', 'max:11', 'regex:/^\d{3}-\d{2}-\d{4}$/'],
            'credit_score' => ['nullable', 'integer', 'min:300', 'max:850'],
            'desired_funding_amount' => ['nullable', 'numeric', 'min:0'],

            // Living Information
            'home_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'monthly_housing_payment' => ['nullable', 'numeric', 'min:0'],
            'housing_status' => ['nullable', 'string', 'in:Rent,Own,Other'],

            // Employer Information
            'employer_name' => ['nullable', 'string', 'max:255'],
            'employer_phone' => ['nullable', 'string', 'max:50'],
            'employer_address' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'annual_income' => ['nullable', 'numeric', 'min:0'],

            // Referrals
            'sales_rep' => ['nullable', 'string', 'max:100'],
            'referral_source' => ['nullable', 'string', 'max:255'],

            // Honeypot (bots fill this)
            '_hp_company' => ['nullable', 'max:0'],

            // File uploads
            'paystub_document' => ['nullable', 'array', 'max:5'],
            'paystub_document.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif', 'max:10240'],
            'credit_report_document' => ['nullable', 'array', 'max:5'],
            'credit_report_document.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => __('embed_form.first_name_required'),
            'last_name.required' => __('embed_form.last_name_required'),
            'email.required' => __('embed_form.email_required'),
            'email.email' => __('embed_form.email_invalid'),
            'ssn.regex' => __('embed_form.ssn_format'),
            'credit_score.min' => __('embed_form.credit_score_min'),
            'credit_score.max' => __('embed_form.credit_score_max'),
            '_hp_company.max' => __('embed_form.honeypot_triggered'),
            'paystub_document.*.mimes' => __('embed_form.paystub_mimes'),
            'paystub_document.*.max' => __('embed_form.paystub_max'),
            'credit_report_document.*.mimes' => __('embed_form.credit_report_mimes'),
            'credit_report_document.*.max' => __('embed_form.credit_report_max'),
        ];
    }
}
