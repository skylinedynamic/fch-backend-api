<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\Ssn;
use App\Rules\UsPhoneNumber;
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
    /**
     * The two consents, which are evidence rather than profile fields.
     *
     * They are validated here because the API must refuse to pull credit
     * without both, but they have no columns on `credit_profile_requests` -
     * they belong in `credit_authorization_consents`, written separately.
     */
    public const CONSENT_FIELDS = [
        'credit_authorization_accepted',
        'e_consent_accepted',
    ];

    /**
     * Just the fields that are columns of the request row.
     *
     * The controller used to hand the whole of validated() to the service. The
     * model is `$guarded = []`, so Eloquent took the consent keys for columns
     * too and every submission died on
     * "Unknown column 'credit_authorization_accepted'".
     */
    public function creditProfileAttributes(): array
    {
        return $this->safe()->except(self::CONSENT_FIELDS);
    }

    /**
     * An SSN is nine digits; the hyphens are only how it is written. Reducing it
     * here means the pull is keyed on one form whichever way the borrower typed
     * it, and that the rule below checks the number rather than the writing.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('ssn')) {
            $this->merge(['ssn' => Ssn::normalize($this->input('ssn'))]);
        }
    }

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
            'ssn' => ['required', new Ssn()],
            'date_of_birth' => 'required|date',
            'phone' => ['required', new UsPhoneNumber()],
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
