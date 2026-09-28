<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\UsPostalCode;

class StoreCreditProfileRequestRequest extends BaseApiRequest
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
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'apt_suite_other' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zip' => ['required', 'string', new UsPostalCode()],
            'ssn' => 'required|string|max:255',
            'date_of_birth' => 'required|date',
            'phone' => 'required|string|max:255',
            'email' => 'required|email|max:255',

            // The FCRA authorisation and the E-SIGN consent, refused here rather
            // than only in the browser: what has to be demonstrable to an auditor
            // is that the API would not pull credit without both. They are two
            // legally distinct consents, so two rules.
            'credit_authorization_accepted' => ['required', 'accepted'],
            'e_consent_accepted' => ['required', 'accepted'],
        ];
    }
}
