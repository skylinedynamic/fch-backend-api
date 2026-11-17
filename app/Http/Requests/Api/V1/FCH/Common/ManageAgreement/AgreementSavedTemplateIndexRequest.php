<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ManageAgreement;

use App\Models\Common\AgreementSavedTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The agreement type whose saved templates the Templates dialog lists. */
class AgreementSavedTemplateIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', Rule::in(AgreementSavedTemplate::slugs())],
        ];
    }
}
