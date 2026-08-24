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
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'ssn.regex' => 'SSN must be in the format XXX-XX-XXXX.',
            'credit_score.min' => 'Credit score must be at least 300.',
            'credit_score.max' => 'Credit score cannot exceed 850.',
            '_hp_company.max' => 'Submission rejected.',
            'paystub_document.*.mimes' => 'Paystub must be a PDF, Word, Excel, or image file.',
            'paystub_document.*.max' => 'Each paystub file may not exceed 10 MB.',
            'credit_report_document.*.mimes' => 'Credit report must be a PDF, Word, Excel, or image file.',
            'credit_report_document.*.max' => 'Each credit report file may not exceed 10 MB.',
        ];
    }
}
