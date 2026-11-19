<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ManageAgreement;

use App\Services\FCH\Common\ManageAgreement\AgreementPlanFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The builder preview's merge field values: the owner's alone, or - Preview as
 * Partner / Borrower - those of one signer of the agreement type too.
 */
class AgreementPreviewValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'signer_user_id' => ['nullable', 'integer'],
            'type' => ['required_with:signer_user_id', 'nullable', 'string', Rule::in(AgreementPlanFields::agreementTypes())],
        ];
    }
}
