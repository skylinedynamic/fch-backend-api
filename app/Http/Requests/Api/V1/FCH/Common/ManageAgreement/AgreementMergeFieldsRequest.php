<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ManageAgreement;

use App\Services\FCH\Common\ManageAgreement\AgreementPlanFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The agreement type whose Plan & Fee dynamic values the builder lists. */
class AgreementMergeFieldsRequest extends FormRequest
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
        ];
    }
}
