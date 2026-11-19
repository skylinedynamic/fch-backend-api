<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ManageAgreement;

use App\Services\FCH\Common\ManageAgreement\AgreementPlanFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Agreement Builder › Preview as Partner / Borrower: whose list of signers, and an optional search. */
class AgreementPreviewSignersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(AgreementPlanFields::agreementTypes())],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
